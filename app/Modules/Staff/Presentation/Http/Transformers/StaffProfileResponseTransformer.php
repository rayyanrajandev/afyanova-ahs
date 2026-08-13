<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

class StaffProfileResponseTransformer
{
    public static function transform(array $profile): array
    {
        return [
            'id' => $profile['id'] ?? null,
            'userId' => $profile['user_id'] ?? null,
            'userName' => $profile['user_name'] ?? null,
            'userEmail' => $profile['user_email'] ?? null,
            'userEmailVerifiedAt' => $profile['user_email_verified_at'] ?? null,
            'userEmailVerified' => ($profile['user_email_verified_at'] ?? null) !== null,
            'employeeNumber' => $profile['employee_number'] ?? null,
            'department' => $profile['department'] ?? null,
            'departmentId' => $profile['department_id'] ?? null,
            'jobTitle' => $profile['job_title'] ?? null,
            'primarySpecialtyId' => $profile['primary_specialty_id'] ?? null,
            'primarySpecialtyCode' => $profile['primary_specialty_code'] ?? null,
            'primarySpecialtyName' => $profile['primary_specialty_name'] ?? null,
            'professionalLicenseNumber' => $profile['professional_license_number'] ?? null,
            'licenseType' => $profile['license_type'] ?? null,
            'phoneExtension' => $profile['phone_extension'] ?? null,
            'employmentType' => $profile['employment_type'] ?? null,
            'status' => $profile['status'] ?? null,
            'statusReason' => $profile['status_reason'] ?? null,
            'createdAt' => $profile['created_at'] ?? null,
            'updatedAt' => $profile['updated_at'] ?? null,
        ];
    }
}
