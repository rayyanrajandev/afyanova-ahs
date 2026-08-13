<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Domain\Repositories\StaffDocumentAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffDocumentRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;

class UpdateStaffDocumentUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffDocumentRepositoryInterface $staffDocumentRepository,
        private readonly StaffDocumentAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $staffProfileId, string $staffDocumentId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        $existing = $this->staffDocumentRepository->findByIdForStaffProfile(
            staffProfileId: $staffProfileId,
            id: $staffDocumentId,
        );
        if (! $existing) {
            return null;
        }

        $updatePayload = [];
        if (array_key_exists('document_type', $payload)) {
            $updatePayload['document_type'] = trim((string) $payload['document_type']);
        }
        if (array_key_exists('title', $payload)) {
            $updatePayload['title'] = trim((string) $payload['title']);
        }
        if (array_key_exists('description', $payload)) {
            $updatePayload['description'] = $this->nullableTrimmedValue($payload['description']);
        }
        if (array_key_exists('issued_at', $payload)) {
            $updatePayload['issued_at'] = $payload['issued_at'];
        }
        if (array_key_exists('expires_at', $payload)) {
            $updatePayload['expires_at'] = $payload['expires_at'];
        }

        $updated = $this->staffDocumentRepository->update($staffDocumentId, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                staffDocumentId: $staffDocumentId,
                action: 'staff-document.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
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
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = ['document_type', 'title', 'description', 'issued_at', 'expires_at'];

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;
            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }
}

