<?php

namespace App\Modules\InpatientWard\Presentation\Http\Transformers;

class InpatientWardDischargeChecklistResponseTransformer
{
    public static function transform(array $checklist): array
    {
        return [
            'id' => $checklist['id'] ?? null,
            'admissionId' => $checklist['admission_id'] ?? null,
            'patientId' => $checklist['patient_id'] ?? null,
            'status' => $checklist['status'] ?? null,
            'statusReason' => $checklist['status_reason'] ?? null,
            'clinicalSummaryCompleted' => (bool) ($checklist['clinical_summary_completed'] ?? false),
            'medicationReconciliationCompleted' => (bool) ($checklist['medication_reconciliation_completed'] ?? false),
            'followUpPlanCompleted' => (bool) ($checklist['follow_up_plan_completed'] ?? false),
            'patientEducationCompleted' => (bool) ($checklist['patient_education_completed'] ?? false),
            'transportArranged' => (bool) ($checklist['transport_arranged'] ?? false),
            'billingCleared' => (bool) ($checklist['billing_cleared'] ?? false),
            'documentationCompleted' => (bool) ($checklist['documentation_completed'] ?? false),
            'isReadyForDischarge' => (bool) ($checklist['is_ready_for_discharge'] ?? false),
            'lastReviewedByUserId' => $checklist['last_reviewed_by_user_id'] ?? null,
            'reviewedAt' => $checklist['reviewed_at'] ?? null,
            'notes' => $checklist['notes'] ?? null,
            'metadata' => $checklist['metadata'] ?? null,
            'createdAt' => $checklist['created_at'] ?? null,
            'updatedAt' => $checklist['updated_at'] ?? null,
        ];
    }
}

