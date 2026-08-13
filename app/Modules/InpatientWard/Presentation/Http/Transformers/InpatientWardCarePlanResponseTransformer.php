<?php

namespace App\Modules\InpatientWard\Presentation\Http\Transformers;

class InpatientWardCarePlanResponseTransformer
{
    public static function transform(array $carePlan): array
    {
        return [
            'id' => $carePlan['id'] ?? null,
            'carePlanNumber' => $carePlan['care_plan_number'] ?? null,
            'admissionId' => $carePlan['admission_id'] ?? null,
            'patientId' => $carePlan['patient_id'] ?? null,
            'title' => $carePlan['title'] ?? null,
            'planText' => $carePlan['plan_text'] ?? null,
            'goals' => $carePlan['goals'] ?? null,
            'interventions' => $carePlan['interventions'] ?? null,
            'targetDischargeAt' => $carePlan['target_discharge_at'] ?? null,
            'reviewDueAt' => $carePlan['review_due_at'] ?? null,
            'status' => $carePlan['status'] ?? null,
            'statusReason' => $carePlan['status_reason'] ?? null,
            'authorUserId' => $carePlan['author_user_id'] ?? null,
            'lastUpdatedByUserId' => $carePlan['last_updated_by_user_id'] ?? null,
            'metadata' => $carePlan['metadata'] ?? null,
            'createdAt' => $carePlan['created_at'] ?? null,
            'updatedAt' => $carePlan['updated_at'] ?? null,
        ];
    }
}

