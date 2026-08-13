<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

class ClinicalCatalogItemResponseTransformer
{
    public static function transform(array $item): array
    {
        $metadata = is_array($item['metadata'] ?? null)
            ? $item['metadata']
            : null;

        $strengthParsed = self::parseStrengthString($item['strength'] ?? null);

        return [
            'id' => $item['id'] ?? null,
            'tenantId' => $item['tenant_id'] ?? null,
            'facilityId' => $item['facility_id'] ?? null,
            'facilityTier' => $item['facility_tier'] ?? null,
            'catalogType' => $item['catalog_type'] ?? null,
            'code' => $item['code'] ?? null,
            'name' => $item['name'] ?? null,
            'genericName' => $item['generic_name'] ?? null,
            'dosageForm' => $item['dosage_form'] ?? null,
            'strength' => $item['strength'] ?? null,
            'strengthNumeratorValue' => $strengthParsed['numeratorValue'] ?? null,
            'strengthNumeratorUnit' => $strengthParsed['numeratorUnit'] ?? null,
            'strengthDenominatorValue' => $strengthParsed['denominatorValue'] ?? null,
            'strengthDenominatorUnit' => $strengthParsed['denominatorUnit'] ?? null,
            'route' => $item['route'] ?? null,
            'containerVolumeValue' => $item['container_volume_value'] ?? null,
            'containerVolumeUnit' => $item['container_volume_unit'] ?? null,
            'storageConditions' => $item['storage_conditions'] ?? null,
            'requiresColdChain' => (bool) ($item['requires_cold_chain'] ?? false),
            'isControlledSubstance' => (bool) ($item['is_controlled_substance'] ?? false),
            'controlledSubstanceSchedule' => $item['controlled_substance_schedule'] ?? null,
            'directServiceEligible' => (bool) ($item['direct_service_eligible'] ?? false),
            'refillableWithoutPrescription' => (bool) ($item['refillable_without_prescription'] ?? false),
            'genericGroupCode' => $item['generic_group_code'] ?? null,
            'departmentId' => $item['department_id'] ?? null,
            'category' => $item['category'] ?? null,
            'unit' => $item['unit'] ?? null,
            'description' => $item['description'] ?? null,
            'billingServiceCode' => self::billingServiceCode($metadata),
            'billingLinkStatus' => self::billingLinkStatus($item['billing_link'] ?? null),
            'billingLink' => self::transformBillingLink($item['billing_link'] ?? null),
            'metadata' => $metadata,
            'codes' => is_array($item['codes'] ?? null) ? $item['codes'] : null,
            'status' => $item['status'] ?? null,
            'statusReason' => $item['status_reason'] ?? null,
            'createdAt' => $item['created_at'] ?? null,
            'updatedAt' => $item['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private static function billingServiceCode(?array $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }

        $candidateCodes = [
            $metadata['billingServiceCode'] ?? null,
            $metadata['billing_service_code'] ?? null,
        ];

        foreach ($candidateCodes as $candidateCode) {
            $normalized = strtoupper(trim((string) $candidateCode));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $billingLink
     */
    private static function billingLinkStatus(mixed $billingLink): ?string
    {
        return is_array($billingLink)
            ? self::nullableTrimmedValue($billingLink['status'] ?? null)
            : null;
    }

    /**
     * @param  mixed  $billingLink
     * @return array<string, mixed>|null
     */
    private static function transformBillingLink(mixed $billingLink): ?array
    {
        if (! is_array($billingLink)) {
            return null;
        }

        $billingItem = is_array($billingLink['item'] ?? null)
            ? $billingLink['item']
            : null;

        return [
            'status' => self::nullableTrimmedValue($billingLink['status'] ?? null),
            'serviceCode' => self::nullableTrimmedValue($billingLink['service_code'] ?? null),
            'item' => $billingItem === null ? null : [
                'id' => $billingItem['id'] ?? null,
                'clinicalCatalogItemId' => $billingItem['clinicalCatalogItemId'] ?? null,
                'serviceCode' => $billingItem['serviceCode'] ?? null,
                'serviceName' => $billingItem['serviceName'] ?? null,
                'status' => $billingItem['status'] ?? null,
                'versionNumber' => $billingItem['versionNumber'] ?? null,
                'basePrice' => $billingItem['basePrice'] ?? null,
                'currencyCode' => $billingItem['currencyCode'] ?? null,
                'effectiveFrom' => $billingItem['effectiveFrom'] ?? null,
                'effectiveTo' => $billingItem['effectiveTo'] ?? null,
            ],
        ];
    }

    private static function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array{numeratorValue: int|float, numeratorUnit: string|null, denominatorValue: int|float, denominatorUnit: string|null}|null
     */
    private static function parseStrengthString(?string $strength): ?array
    {
        if ($strength === null || $strength === '') {
            return null;
        }

        $strength = trim($strength);

        if (preg_match('/^([\d.]+)\s*([a-zA-Z°%]+)(?:\s*\/\s*([\d.]+)\s*([a-zA-Z°%]+))?$/', $strength, $m)) {
            $numValue = is_numeric($m[1]) ? (str_contains($m[1], '.') ? (float) $m[1] : (int) $m[1]) : 0;
            $numUnit = $m[2] !== '' ? $m[2] : null;

            if (isset($m[3], $m[4]) && $m[3] !== '' && $m[4] !== '') {
                $denValue = is_numeric($m[3]) ? (str_contains($m[3], '.') ? (float) $m[3] : (int) $m[3]) : 1;
                $denUnit = $m[4] !== '' ? $m[4] : null;
            } else {
                $denValue = 1;
                $denUnit = null;
            }

            return [
                'numeratorValue' => $numValue,
                'numeratorUnit' => $numUnit,
                'denominatorValue' => $denValue,
                'denominatorUnit' => $denUnit,
            ];
        }

        return null;
    }
}
