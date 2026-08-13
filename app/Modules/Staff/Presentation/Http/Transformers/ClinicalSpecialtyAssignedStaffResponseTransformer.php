<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

class ClinicalSpecialtyAssignedStaffResponseTransformer
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
            'jobTitle' => $profile['job_title'] ?? null,
            'employmentType' => $profile['employment_type'] ?? null,
            'status' => $profile['status'] ?? null,
            'statusReason' => $profile['status_reason'] ?? null,
            'isPrimary' => (bool) ($profile['is_primary'] ?? false),
            'assignedAt' => $profile['assigned_at'] ?? null,
            'assignmentUpdatedAt' => $profile['assignment_updated_at'] ?? null,
        ];
    }
}
