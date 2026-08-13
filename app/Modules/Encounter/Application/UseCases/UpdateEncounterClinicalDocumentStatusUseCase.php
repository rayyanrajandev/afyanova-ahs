<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Encounter\Domain\Repositories\EncounterAuditLogRepositoryInterface;
use App\Modules\Encounter\Domain\Repositories\EncounterClinicalDocumentRepositoryInterface;
use App\Modules\Encounter\Domain\ValueObjects\EncounterClinicalDocumentStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateEncounterClinicalDocumentStatusUseCase
{
    public function __construct(
        private readonly EncounterResolverService $encounterResolverService,
        private readonly EncounterClinicalDocumentRepositoryInterface $documentRepository,
        private readonly EncounterAuditLogRepositoryInterface $encounterAuditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $encounterId,
        string $documentId,
        string $status,
        ?string $reason,
        ?int $actorId = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $encounter = $this->encounterResolverService->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        $existing = $this->documentRepository->findByIdForEncounter(
            encounterId: $encounterId,
            id: $documentId,
        );
        if (! $existing) {
            return null;
        }

        $normalizedStatus = in_array($status, EncounterClinicalDocumentStatus::values(), true)
            ? $status
            : EncounterClinicalDocumentStatus::ACTIVE->value;
        $normalizedReason = $this->nullableTrimmedValue($reason);

        $updated = $this->documentRepository->update($documentId, [
            'status' => $normalizedStatus,
            'status_reason' => $normalizedStatus === EncounterClinicalDocumentStatus::ARCHIVED->value
                ? $normalizedReason
                : null,
        ]);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->encounterAuditLogRepository->write(
                encounterId: $encounterId,
                action: 'encounter.clinical-document.status.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'clinical_document_id' => $documentId,
                    'transition' => [
                        'from' => $existing['status'] ?? null,
                        'to' => $updated['status'] ?? null,
                    ],
                    'reason_required' => $normalizedStatus === EncounterClinicalDocumentStatus::ARCHIVED->value,
                    'reason_provided' => $normalizedReason !== null,
                ],
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
        $trackedFields = ['status', 'status_reason'];

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
