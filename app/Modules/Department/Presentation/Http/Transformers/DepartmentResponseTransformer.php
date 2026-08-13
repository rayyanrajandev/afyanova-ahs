<?php

namespace App\Modules\Department\Presentation\Http\Transformers;

class DepartmentResponseTransformer
{
    public static function transform(array $department): array
    {
        return [
            'id' => $department['id'] ?? null,
            'tenantId' => $department['tenant_id'] ?? null,
            'facilityId' => $department['facility_id'] ?? null,
            'code' => $department['code'] ?? null,
            'name' => $department['name'] ?? null,
            'serviceType' => $department['service_type'] ?? null,
            'isPatientFacing' => (bool) ($department['is_patient_facing'] ?? false),
            'isAppointmentable' => (bool) ($department['is_appointmentable'] ?? false),
            'managerUserId' => $department['manager_user_id'] ?? null,
            'manager' => self::transformManager($department['manager'] ?? null),
            'status' => $department['status'] ?? null,
            'statusReason' => $department['status_reason'] ?? null,
            'description' => $department['description'] ?? null,
            'createdAt' => $department['created_at'] ?? null,
            'updatedAt' => $department['updated_at'] ?? null,
        ];
    }

    private static function transformManager(mixed $manager): ?array
    {
        if (! is_array($manager)) {
            return null;
        }

        return [
            'userId' => $manager['user_id'] ?? null,
            'displayName' => $manager['display_name'] ?? null,
            'email' => $manager['email'] ?? null,
            'staffProfileId' => $manager['staff_profile_id'] ?? null,
            'staffStatus' => $manager['staff_status'] ?? null,
        ];
    }
}
