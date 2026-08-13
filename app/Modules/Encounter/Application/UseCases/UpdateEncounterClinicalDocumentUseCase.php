<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Encounter\Domain\Repositories\EncounterAuditLogRepositoryInterface;
use App\Modules\Encounter\Domain\Repositories\EncounterClinicalDocumentRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateEncounterClinicalDocumentUseCase
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
        array $payload,
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

        $updated = $this->documentRepository->update($documentId, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->encounterAuditLogRepository->write(
                encounterId: $encounterId,
                action: 'encounter.clinical-document.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'clinical_document_id' => $documentId,
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
        $trackedFields = ['document_type', 'title', 'description'];

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
