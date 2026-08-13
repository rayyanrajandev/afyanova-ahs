<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class PatientInsuranceAuditEventResponseTransformer
{
    public static function transform(array $event): array
    {
        return [
            'id' => $event['id'] ?? null,
            'patientInsuranceRecordId' => $event['patient_insurance_record_id'] ?? null,
            'patientId' => $event['patient_id'] ?? null,
            'actorUserId' => $event['actor_user_id'] ?? null,
            'action' => $event['action'] ?? null,
            'changes' => $event['changes'] ?? null,
            'metadata' => $event['metadata'] ?? null,
            'createdAt' => $event['created_at'] ?? null,
        ];
    }
}
