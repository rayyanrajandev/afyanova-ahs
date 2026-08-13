<?php

namespace App\Modules\Patient\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class PatientAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'patientId' => $log['patient_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log, [
            'patient.created' => 'Patient Registered',
            'patient.updated' => 'Patient Profile Updated',
            'patient.status.updated' => 'Patient Status Updated',
            // Bug fix 2026-08-11 — see CheckInUseCase/CancelQueueItemUseCase
            // docblocks: visit lifecycle events previously never reached this
            // feed at all.
            'patient.visit.checked_in' => 'Patient Checked In',
            'patient.visit.cancelled' => 'Visit Cancelled',
        ]);
    }
}
