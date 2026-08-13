<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

class ClinicalPrivilegeCatalogResponseTransformer
{
    public static function transform(array $catalog): array
    {
        return [
            'id' => $catalog['id'] ?? null,
            'tenantId' => $catalog['tenant_id'] ?? null,
            'specialtyId' => $catalog['specialty_id'] ?? null,
            'code' => $catalog['code'] ?? null,
            'name' => $catalog['name'] ?? null,
            'description' => $catalog['description'] ?? null,
            'cadreCode' => $catalog['cadre_code'] ?? null,
            'facilityType' => $catalog['facility_type'] ?? null,
            'status' => $catalog['status'] ?? null,
            'statusReason' => $catalog['status_reason'] ?? null,
            'createdAt' => $catalog['created_at'] ?? null,
            'updatedAt' => $catalog['updated_at'] ?? null,
        ];
    }
}
