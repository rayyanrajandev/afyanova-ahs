<?php

namespace App\Modules\Appointment\Presentation\Http\Transformers;

class AppointmentReferralResponseTransformer
{
    public static function transform(array $referral): array
    {
        return [
            'id' => $referral['id'] ?? null,
            'appointmentId' => $referral['appointment_id'] ?? null,
            'referralNumber' => $referral['referral_number'] ?? null,
            'referralType' => $referral['referral_type'] ?? null,
            'priority' => $referral['priority'] ?? null,
            'targetDepartment' => $referral['target_department'] ?? null,
            'targetFacilityId' => $referral['target_facility_id'] ?? null,
            'targetFacilityCode' => $referral['target_facility_code'] ?? null,
            'targetFacilityName' => $referral['target_facility_name'] ?? null,
            'targetClinicianUserId' => $referral['target_clinician_user_id'] ?? null,
            'referralReason' => $referral['referral_reason'] ?? null,
            'clinicalNotes' => $referral['clinical_notes'] ?? null,
            'handoffNotes' => $referral['handoff_notes'] ?? null,
            'requestedAt' => $referral['requested_at'] ?? null,
            'acceptedAt' => $referral['accepted_at'] ?? null,
            'handedOffAt' => $referral['handed_off_at'] ?? null,
            'completedAt' => $referral['completed_at'] ?? null,
            'status' => $referral['status'] ?? null,
            'statusReason' => $referral['status_reason'] ?? null,
            'metadata' => $referral['metadata'] ?? null,
            'createdAt' => $referral['created_at'] ?? null,
            'updatedAt' => $referral['updated_at'] ?? null,
        ];
    }
}
