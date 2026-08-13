<?php

namespace App\Modules\Pos\Application\Support;

use App\Modules\Pos\Application\Exceptions\PosOperationException;

class PharmacyOtcCatalogSupport
{
    /**
     * @return array{
     *     dosageForm:?string,
     *     strength:?string,
     *     reviewMode:?string,
     *     otcEligible:bool,
     *     otcEligibilityReason:?string,
     *     unitPrice:?float,
     *     unitPriceSource:?string
     * }
     */
    public function otcContext(array $catalogItem): array
    {
        $metadata = $this->metadata($catalogItem);
        $dosageForm = $this->firstText($metadata, ['dosageForm', 'dosage_form', 'form']);
        $strength = $this->firstText($metadata, ['strength', 'strengthLabel', 'strength_label']);
        $reviewMode = strtolower($this->firstText($metadata, ['reviewMode', 'review_mode']) ?? 'auto_formulary');
        $unitPrice = $this->firstMoney($metadata, [
            'otcUnitPrice',
            'otc_unit_price',
            'retailPrice',
            'retail_price',
            'sellingPrice',
            'selling_price',
            'cashPrice',
            'cash_price',
            'unitPrice',
            'unit_price',
        ]);

        $otcEligible = true;
        $otcEligibilityReason = null;

        if ($reviewMode === 'policy_review_required') {
            $otcEligible = false;
            $otcEligibilityReason = 'This medicine requires pharmacy policy review and cannot be sold through OTC POS.';
        } elseif ($this->looksInjectable($dosageForm, (string) ($catalogItem['unit'] ?? null))) {
            $otcEligible = false;
            $otcEligibilityReason = 'Parenteral medicines are not eligible for OTC walk-in sale.';
        }

        return [
            'dosageForm' => $dosageForm,
            'strength' => $strength,
            'reviewMode' => $reviewMode !== '' ? $reviewMode : null,
            'otcEligible' => $otcEligible,
            'otcEligibilityReason' => $otcEligibilityReason,
            'unitPrice' => $unitPrice,
            'unitPriceSource' => $unitPrice !== null ? $this->unitPriceSource($metadata) : null,
        ];
    }

    /**
     * @return array{unitPrice:float, source:string}
     */
    public function resolveUnitPrice(array $catalogItem, mixed $requestedValue, string $field): array
    {
        $requestedUnitPrice = $this->normalizeMoney($requestedValue);
        if ($requestedUnitPrice !== null && $requestedUnitPrice > 0) {
            return [
                'unitPrice' => $requestedUnitPrice,
                'source' => 'manual_entry',
            ];
        }

        $context = $this->otcContext($catalogItem);
        if (($context['unitPrice'] ?? null) !== null) {
            return [
                'unitPrice' => (float) $context['unitPrice'],
                'source' => (string) ($context['unitPriceSource'] ?? 'catalog_metadata'),
            ];
        }

        throw new PosOperationException(
            'Unit price is required because this approved medicine does not have an OTC price hint.',
            $field,
        );
    }

    public function stockState(?array $inventoryItem): ?string
    {
        if ($inventoryItem === null) {
            return null;
        }

        $currentStock = (float) ($inventoryItem['current_stock'] ?? 0);
        $reorderLevel = (float) ($inventoryItem['reorder_level'] ?? 0);

        if ($currentStock <= 0) {
            return 'out_of_stock';
        }

        if ($currentStock <= $reorderLevel) {
            return 'low_stock';
        }

        return 'healthy';
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(array $catalogItem): array
    {
        return is_array($catalogItem['metadata'] ?? null)
            ? $catalogItem['metadata']
            : [];
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<int, string> $keys
     */
    private function firstText(array $metadata, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($metadata[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<int, string> $keys
     */
    private function firstMoney(array $metadata, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $this->normalizeMoney($metadata[$key] ?? null);
            if ($value !== null && $value > 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function unitPriceSource(array $metadata): string
    {
        $preferredKeys = [
            'otcUnitPrice' => 'catalog_metadata.otcUnitPrice',
            'otc_unit_price' => 'catalog_metadata.otc_unit_price',
            'retailPrice' => 'catalog_metadata.retailPrice',
            'retail_price' => 'catalog_metadata.retail_price',
            'sellingPrice' => 'catalog_metadata.sellingPrice',
            'selling_price' => 'catalog_metadata.selling_price',
            'cashPrice' => 'catalog_metadata.cashPrice',
            'cash_price' => 'catalog_metadata.cash_price',
            'unitPrice' => 'catalog_metadata.unitPrice',
            'unit_price' => 'catalog_metadata.unit_price',
        ];

        foreach ($preferredKeys as $key => $source) {
            $value = $this->normalizeMoney($metadata[$key] ?? null);
            if ($value !== null && $value > 0) {
                return $source;
            }
        }

        return 'catalog_metadata';
    }

    private function looksInjectable(?string $dosageForm, string $unit): bool
    {
        $normalizedDosageForm = strtolower(trim((string) $dosageForm));
        $normalizedUnit = strtolower(trim($unit));

        foreach ([$normalizedDosageForm, $normalizedUnit] as $value) {
            if ($value === '') {
                continue;
            }

            if (
                str_contains($value, 'inject')
                || str_contains($value, 'infusion')
                || str_contains($value, 'ampoule')
                || str_contains($value, 'vial')
            ) {
                return true;
            }
        }

        return false;
    }

    private function normalizeMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}
