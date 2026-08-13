<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Encounter\Domain\Repositories\EncounterAuditLogRepositoryInterface;
use App\Modules\Encounter\Domain\Repositories\EncounterClinicalDocumentRepositoryInterface;
use App\Modules\Encounter\Domain\ValueObjects\EncounterClinicalDocumentStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateEncounterClinicalDocumentUseCase
{
    public function __construct(
        private readonly EncounterResolverService $encounterResolverService,
        private readonly EncounterClinicalDocumentRepositoryInterface $documentRepository,
        private readonly EncounterAuditLogRepositoryInterface $encounterAuditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $encounterId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $encounter = $this->encounterResolverService->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        $created = $this->documentRepository->create([
            'encounter_id' => $encounterId,
            'patient_id' => (string) $encounter->patient_id,
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'document_type' => trim((string) $payload['document_type']),
            'title' => trim((string) $payload['title']),
            'description' => $this->nullableTrimmedValue($payload['description'] ?? null),
            'file_path' => (string) $payload['file_path'],
            'original_filename' => (string) $payload['original_filename'],
            'mime_type' => (string) $payload['mime_type'],
            'file_size_bytes' => (int) $payload['file_size_bytes'],
            'checksum_sha256' => (string) $payload['checksum_sha256'],
            'status' => EncounterClinicalDocumentStatus::ACTIVE->value,
            'status_reason' => null,
            'uploaded_by_user_id' => $actorId,
        ]);

        $this->encounterAuditLogRepository->write(
            encounterId: $encounterId,
            action: 'encounter.clinical-document.uploaded',
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
            'id',
            'encounter_id',
            'patient_id',
            'document_type',
            'title',
            'description',
            'original_filename',
            'mime_type',
            'file_size_bytes',
            'status',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $document[$field] ?? null;
        }

        return $result;
    }
}
