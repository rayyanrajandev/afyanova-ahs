<?php

namespace App\Modules\Pos\Presentation\Http\Transformers;

class PosRegisterResponseTransformer
{
    public static function transform(array $register): array
    {
        return [
            'id' => $register['id'] ?? null,
            'tenantId' => $register['tenant_id'] ?? null,
            'facilityId' => $register['facility_id'] ?? null,
            'registerCode' => $register['register_code'] ?? null,
            'registerName' => $register['register_name'] ?? null,
            'location' => $register['location'] ?? null,
            'defaultCurrencyCode' => $register['default_currency_code'] ?? null,
            'status' => $register['status'] ?? null,
            'statusReason' => $register['status_reason'] ?? null,
            'notes' => $register['notes'] ?? null,
            'createdByUserId' => $register['created_by_user_id'] ?? null,
            'updatedByUserId' => $register['updated_by_user_id'] ?? null,
            'currentOpenSession' => is_array($register['current_open_session'] ?? null)
                ? PosRegisterSessionResponseTransformer::transformSummary($register['current_open_session'])
                : null,
            'createdAt' => $register['created_at'] ?? null,
            'updatedAt' => $register['updated_at'] ?? null,
        ];
    }

    public static function transformSummary(array $register): array
    {
        return [
            'id' => $register['id'] ?? null,
            'registerCode' => $register['register_code'] ?? null,
            'registerName' => $register['register_name'] ?? null,
            'location' => $register['location'] ?? null,
            'status' => $register['status'] ?? null,
            'defaultCurrencyCode' => $register['default_currency_code'] ?? null,
        ];
    }
}
