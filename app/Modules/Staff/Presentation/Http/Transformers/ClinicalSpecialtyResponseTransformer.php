<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

class ClinicalSpecialtyResponseTransformer
{
    public static function transform(array $specialty): array
    {
        return [
            'id' => $specialty['id'] ?? null,
            'tenantId' => $specialty['tenant_id'] ?? null,
            'code' => $specialty['code'] ?? null,
            'name' => $specialty['name'] ?? null,
            'description' => $specialty['description'] ?? null,
            'status' => $specialty['status'] ?? null,
            'statusReason' => $specialty['status_reason'] ?? null,
            'createdAt' => $specialty['created_at'] ?? null,
            'updatedAt' => $specialty['updated_at'] ?? null,
        ];
    }
}

