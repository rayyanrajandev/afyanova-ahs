<?php

namespace App\Modules\MedicalRecord\Presentation\Http\Transformers;

class MedicalRecordResponseTransformer
{
    public static function transform(array $record): array
    {
        return [
            'id' => $record['id'] ?? null,
            'recordNumber' => $record['record_number'] ?? null,
            'patientId' => $record['patient_id'] ?? null,
            'encounterId' => $record['encounter_id'] ?? null,
            'admissionId' => $record['admission_id'] ?? null,
            'appointmentId' => $record['appointment_id'] ?? null,
            'appointmentReferralId' => $record['appointment_referral_id'] ?? null,
            'theatreProcedureId' => $record['theatre_procedure_id'] ?? null,
            'authorUserId' => $record['author_user_id'] ?? null,
            'handedOffToUserId' => $record['handed_off_to_user_id'] ?? null,
            'handoffInitiatedByUserId' => $record['handoff_initiated_by_user_id'] ?? null,
            'handoffStatus' => $record['handoff_status'] ?? null,
            'handoffNote' => $record['handoff_note'] ?? null,
            'handedOffAt' => $record['handed_off_at'] ?? null,
            'handedOffToUserName' => $record['handed_off_to_user']['name'] ?? null,
            'handoffInitiatedByUserName' => $record['handoff_initiated_by_user']['name'] ?? null,
            'encounterAt' => $record['encounter_at'] ?? null,
            'recordType' => $record['record_type'] ?? null,
            'subjective' => $record['subjective'] ?? null,
            'objective' => $record['objective'] ?? null,
            'assessment' => $record['assessment'] ?? null,
            'plan' => $record['plan'] ?? null,
            'diagnosisCode' => $record['diagnosis_code'] ?? null,
            'status' => $record['status'] ?? null,
            'statusReason' => $record['status_reason'] ?? null,
            'signedByUserId' => $record['signed_by_user_id'] ?? null,
            'signedByUserName' => $record['signed_by_user']['name'] ?? null,
            'authorUserName' => $record['author_user']['name'] ?? null,
            'signedAt' => $record['signed_at'] ?? null,
            'createdAt' => $record['created_at'] ?? null,
            'updatedAt' => $record['updated_at'] ?? null,
        ];
    }
}
