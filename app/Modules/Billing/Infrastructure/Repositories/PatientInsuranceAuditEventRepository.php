<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Infrastructure\Models\PatientInsuranceAuditEventModel;

class PatientInsuranceAuditEventRepository
{
    public function write(
        string $patientInsuranceRecordId,
        string $patientId,
        string $action,
        ?int $actorId = null,
        ?array $changes = null,
        ?array $metadata = null,
    ): array {
        $event = PatientInsuranceAuditEventModel::create([
            'patient_insurance_record_id' => $patientInsuranceRecordId,
            'patient_id' => $patientId,
            'actor_user_id' => $actorId,
            'action' => $action,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        return $event->toArray();
    }

    public function listForPatient(string $patientId, int $page, int $perPage): array
    {
        $paginator = PatientInsuranceAuditEventModel::query()
            ->where('patient_id', $patientId)
            ->latest('created_at')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return [
            'data' => array_map(
                static fn (PatientInsuranceAuditEventModel $event): array => $event->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }
}
