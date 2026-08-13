<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Domain\Repositories\StaffDocumentAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffDocumentRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffDocumentStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffDocumentVerificationStatus;

class CreateStaffDocumentUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffDocumentRepositoryInterface $staffDocumentRepository,
        private readonly StaffDocumentAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $staffProfileId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        $created = $this->staffDocumentRepository->create([
            'staff_profile_id' => $staffProfileId,
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'document_type' => trim((string) $payload['document_type']),
            'title' => trim((string) $payload['title']),
            'description' => $this->nullableTrimmedValue($payload['description'] ?? null),
            'file_path' => (string) $payload['file_path'],
            'original_filename' => (string) $payload['original_filename'],
            'mime_type' => (string) $payload['mime_type'],
            'file_size_bytes' => (int) $payload['file_size_bytes'],
            'checksum_sha256' => (string) $payload['checksum_sha256'],
            'issued_at' => $payload['issued_at'] ?? null,
            'expires_at' => $payload['expires_at'] ?? null,
            'verification_status' => StaffDocumentVerificationStatus::PENDING->value,
            'verification_reason' => null,
            'status' => StaffDocumentStatus::ACTIVE->value,
            'status_reason' => null,
            'uploaded_by_user_id' => $actorId,
            'verified_by_user_id' => null,
            'verified_at' => null,
        ]);

        $this->auditLogRepository->write(
            staffDocumentId: $created['id'],
            action: 'staff-document.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $document): array
    {
        $tracked = [
            'staff_profile_id',
            'tenant_id',
            'document_type',
            'title',
            'description',
            'file_path',
            'original_filename',
            'mime_type',
            'file_size_bytes',
            'checksum_sha256',
            'issued_at',
            'expires_at',
            'verification_status',
            'status',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $document[$field] ?? null;
        }

        return $result;
    }
}

