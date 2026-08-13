<?php

namespace App\Support\CatalogGovernance;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;

class StandardsCodeSupport
{
    /**
     * @var array<int, string>
     */
    public const SUPPORTED_CODES = [
        'LOCAL',
        'LOINC',
        'SNOMED_CT',
        'GS1_GTIN',
        'NHIF',
        'MSD',
        'CPT',
        'ICD',
    ];

    /**
     * @param  array<string, mixed>|null  $codes
     * @return array<string, string>|null
     */
    public function normalize(?array $codes): ?array
    {
        if ($codes === null) {
            return null;
        }

        $normalized = [];
        foreach (self::SUPPORTED_CODES as $codeSystem) {
            $value = $codes[$codeSystem] ?? $codes[strtolower($codeSystem)] ?? null;
            if ($value === null) {
                continue;
            }

            $text = trim((string) $value);
            if ($text !== '') {
                $normalized[$codeSystem] = $text;
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, string>
     */
    public function warningsForBillingItem(array $item): array
    {
        $codes = $this->normalize(is_array($item['codes'] ?? null) ? $item['codes'] : null) ?? [];
        $warnings = [];

        if (($codes['NHIF'] ?? null) === null) {
            $warnings[] = 'NHIF code is missing. This does not block saving, but tariff/claims mapping must be confirmed before go-live.';
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, string>
     */
    public function warningsForInventoryItem(array $item): array
    {
        $codes = $this->normalize(is_array($item['codes'] ?? null) ? $item['codes'] : null) ?? [];
        $warnings = [];
        $category = (string) ($item['category'] ?? '');

        $isProcured = $category !== '';
        if ($isProcured && (($codes['MSD'] ?? null) === null) && trim((string) ($item['msd_code'] ?? '')) === '') {
            $warnings[] = 'MSD code is missing. This does not block saving, but procurement mapping should be completed.';
        }

        if (
            $category === InventoryItemCategory::PHARMACEUTICAL->value
            && (($codes['NHIF'] ?? null) === null)
            && trim((string) ($item['nhif_code'] ?? '')) === ''
        ) {
            $warnings[] = 'NHIF code is missing. This does not block saving, but medicine billing/claims mapping should be completed.';
        }

        return $warnings;
    }
}
