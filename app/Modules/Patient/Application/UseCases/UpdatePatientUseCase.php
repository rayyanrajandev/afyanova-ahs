<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Application\Services\PatientDuplicateDetectionService;
use App\Modules\Patient\Domain\Repositories\PatientAuditLogRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Patient\Domain\ValueObjects\PatientPhoneNumber;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdatePatientUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly PatientDuplicateDetectionService $duplicateDetectionService,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $before = $this->patientRepository->findById($id);
        if (! $before) {
            return null;
        }

        $candidate = array_merge($before, $payload);
        $warnings = $this->duplicateDetectionService->evaluate($candidate, $id);

        if (array_key_exists('phone', $payload)) {
            $payload['phone_normalized'] = PatientPhoneNumber::normalize($payload['phone']) ?: null;
        }

        $updated = $this->patientRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($before, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                patientId: $id,
                action: 'patient.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return [
            'patient' => $updated,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'first_name',
            'middle_name',
            'last_name',
            'gender',
            'date_of_birth',
            'phone',
            'email',
            'national_id',
            'country_code',
            'region',
            'district',
            'address_line',
            'next_of_kin_name',
            'next_of_kin_phone',
            'status',
            'status_reason',
        ];

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
