<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

class StaffCredentialingAlertResponseTransformer
{
    public static function transform(array $alert): array
    {
        return [
            'id' => $alert['id'] ?? null,
            'staffProfileId' => $alert['staffProfileId'] ?? null,
            'tenantId' => $alert['tenantId'] ?? null,
            'userName' => $alert['userName'] ?? null,
            'employeeNumber' => $alert['employeeNumber'] ?? null,
            'department' => $alert['department'] ?? null,
            'jobTitle' => $alert['jobTitle'] ?? null,
            'regulatorCode' => $alert['regulatorCode'] ?? null,
            'cadreCode' => $alert['cadreCode'] ?? null,
            'alertType' => $alert['alertType'] ?? null,
            'alertState' => $alert['alertState'] ?? null,
            'summary' => $alert['summary'] ?? null,
            'expiresAt' => $alert['expiresAt'] ?? null,
            'staffProfessionalRegistrationId' => $alert['staffProfessionalRegistrationId'] ?? null,
            'createdAt' => $alert['createdAt'] ?? null,
        ];
    }
}
