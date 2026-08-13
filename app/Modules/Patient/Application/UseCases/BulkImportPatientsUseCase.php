<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Application\Services\PatientMrnGenerator;
use App\Modules\Patient\Application\Support\PatientCsvSchema;
use App\Modules\Patient\Domain\Repositories\PatientAuditLogRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

/**
 * Backup/restore for the patient registry (dev/testing tool): unlike
 * CreatePatientUseCase, this deliberately bypasses duplicate detection and
 * patient-number auto-generation — rows come from a previous export of this
 * same system, not a new clerk-entered registration, so the CSV's id,
 * patient_number, and status are authoritative and are upserted as-is.
 */
class BulkImportPatientsUseCase
{
    private const MAX_ROWS = 1000;

    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly PatientMrnGenerator $patientMrnGenerator,
    ) {}

    /**
     * @param  array<int, array{rowNumber: int, values: array<string, string>}>  $rows
     * @return array{
     *     dry_run: bool,
     *     requested_count: int,
     *     created_count: int,
     *     updated_count: int,
     *     failed_count: int,
     *     results: array<int, array{rowNumber: int, outcome: string, patientId: ?string, errors: array<int, string>}>,
     * }
     */
    public function execute(array $rows, bool $dryRun, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $rows = array_slice($rows, 0, self::MAX_ROWS);

        $createdCount = 0;
        $updatedCount = 0;
        $failedCount = 0;
        $results = [];

        foreach ($rows as $row) {
            $rowNumber = (int) ($row['rowNumber'] ?? 0);
            $values = $row['values'] ?? [];

            $mapped = PatientCsvSchema::fromCsvRow($values, $rowNumber);

            if ($mapped['errors'] !== []) {
                $failedCount++;
                $results[] = [
                    'rowNumber' => $rowNumber,
                    'outcome' => 'failed',
                    'patientId' => null,
                    'errors' => array_values($mapped['errors']),
                ];

                continue;
            }

            $payload = $mapped['payload'];
            $existingId = $payload['id'] ?? null;
            $existing = $existingId !== null ? $this->patientRepository->findById($existingId) : null;

            if ($existing !== null) {
                $updatedCount++;
                $outcome = $dryRun ? 'would_update' : 'updated';

                if (! $dryRun) {
                    unset($payload['id']);
                    $this->patientRepository->update($existingId, $payload);
                    $this->auditLogRepository->write(
                        patientId: $existingId,
                        action: 'patient.restored',
                        actorId: $actorId,
                        changes: ['after' => $this->extractIdentity($payload)],
                    );
                }

                $results[] = [
                    'rowNumber' => $rowNumber,
                    'outcome' => $outcome,
                    'patientId' => $existingId,
                    'errors' => [],
                ];

                continue;
            }

            $createdCount++;
            $outcome = $dryRun ? 'would_create' : 'created';
            $patientId = $existingId;

            if (! $dryRun) {
                $payload['tenant_id'] = $payload['tenant_id'] ?? $this->platformScopeContext->tenantId();
                if (empty($payload['patient_number'])) {
                    $payload['patient_number'] = $this->patientMrnGenerator->nextForTenant(
                        $payload['tenant_id'] ?? $this->platformScopeContext->tenantId(),
                    );
                }

                $created = $this->patientRepository->create($payload);
                $patientId = $created['id'];

                $this->auditLogRepository->write(
                    patientId: $patientId,
                    action: 'patient.restored',
                    actorId: $actorId,
                    changes: ['after' => $this->extractIdentity($payload)],
                );
            }

            $results[] = [
                'rowNumber' => $rowNumber,
                'outcome' => $outcome,
                'patientId' => $patientId,
                'errors' => [],
            ];
        }

        return [
            'dry_run' => $dryRun,
            'requested_count' => count($rows),
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractIdentity(array $payload): array
    {
        return [
            'first_name' => $payload['first_name'] ?? null,
            'last_name' => $payload['last_name'] ?? null,
            'date_of_birth' => $payload['date_of_birth'] ?? null,
            'phone' => $payload['phone'] ?? null,
        ];
    }
}
