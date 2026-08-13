<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

class StaffProfileSpecialtyResponseTransformer
{
    public static function transform(array $assignment): array
    {
        return [
            'id' => $assignment['id'] ?? null,
            'staffProfileId' => $assignment['staff_profile_id'] ?? null,
            'specialtyId' => $assignment['specialty_id'] ?? null,
            'isPrimary' => (bool) ($assignment['is_primary'] ?? false),
            'specialty' => [
                'id' => $assignment['specialty_id'] ?? null,
                'tenantId' => $assignment['tenant_id'] ?? null,
                'code' => $assignment['code'] ?? null,
                'name' => $assignment['name'] ?? null,
                'description' => $assignment['description'] ?? null,
                'status' => $assignment['status'] ?? null,
                'statusReason' => $assignment['status_reason'] ?? null,
            ],
            'createdAt' => $assignment['created_at'] ?? null,
            'updatedAt' => $assignment['updated_at'] ?? null,
        ];
    }
}

