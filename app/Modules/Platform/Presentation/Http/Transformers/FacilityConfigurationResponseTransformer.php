<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

class FacilityConfigurationResponseTransformer
{
    public static function transform(array $facility): array
    {
        return [
            'id' => $facility['id'] ?? null,
            'tenantId' => $facility['tenant_id'] ?? null,
            'tenantCode' => $facility['tenant_code'] ?? null,
            'tenantName' => $facility['tenant_name'] ?? null,
            'tenantCountryCode' => $facility['tenant_country_code'] ?? null,
            'tenantAllowedCountryCodes' => array_values(is_array($facility['tenant_allowed_country_codes'] ?? null) ? $facility['tenant_allowed_country_codes'] : []),
            'code' => $facility['code'] ?? null,
            'name' => $facility['name'] ?? null,
            'facilityType' => $facility['facility_type'] ?? null,
            'facilityTier' => $facility['facility_tier'] ?? null,
            'timezone' => $facility['timezone'] ?? null,
            'status' => $facility['status'] ?? null,
            'statusReason' => $facility['status_reason'] ?? null,
            'operationsOwnerUserId' => $facility['operations_owner_user_id'] ?? null,
            'clinicalOwnerUserId' => $facility['clinical_owner_user_id'] ?? null,
            'administrativeOwnerUserId' => $facility['administrative_owner_user_id'] ?? null,
            'operationsOwner' => self::userSummary($facility['operations_owner'] ?? null),
            'clinicalOwner' => self::userSummary($facility['clinical_owner'] ?? null),
            'administrativeOwner' => self::userSummary($facility['administrative_owner'] ?? null),
            'createdAt' => $facility['created_at'] ?? null,
            'updatedAt' => $facility['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $user
     * @return array<string, mixed>|null
     */
    private static function userSummary(?array $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user['id'] ?? null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'status' => $user['status'] ?? null,
        ];
    }
}
