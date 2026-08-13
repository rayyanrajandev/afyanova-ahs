<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

class StaffRegulatoryProfileResponseTransformer
{
    public static function transform(array $profile): array
    {
        return [
            'id' => $profile['id'] ?? null,
            'staffProfileId' => $profile['staff_profile_id'] ?? null,
            'tenantId' => $profile['tenant_id'] ?? null,
            'primaryRegulatorCode' => $profile['primary_regulator_code'] ?? null,
            'cadreCode' => $profile['cadre_code'] ?? null,
            'professionalTitle' => $profile['professional_title'] ?? null,
            'registrationType' => $profile['registration_type'] ?? null,
            'practiceAuthorityLevel' => $profile['practice_authority_level'] ?? null,
            'supervisionLevel' => $profile['supervision_level'] ?? null,
            'goodStandingStatus' => $profile['good_standing_status'] ?? null,
            'goodStandingCheckedAt' => $profile['good_standing_checked_at'] ?? null,
            'notes' => $profile['notes'] ?? null,
            'createdByUserId' => $profile['created_by_user_id'] ?? null,
            'updatedByUserId' => $profile['updated_by_user_id'] ?? null,
            'createdAt' => $profile['created_at'] ?? null,
            'updatedAt' => $profile['updated_at'] ?? null,
        ];
    }
}
