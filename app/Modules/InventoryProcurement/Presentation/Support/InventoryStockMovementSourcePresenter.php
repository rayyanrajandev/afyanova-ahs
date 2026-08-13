<?php

namespace App\Modules\InventoryProcurement\Presentation\Support;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementSourceKind;
use Illuminate\Support\Str;

class InventoryStockMovementSourcePresenter
{
    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     reference: ?string,
     *     detail: ?string
     * }
     */
    public static function describe(array $movement): array
    {
        $metadata = is_array($movement['metadata'] ?? null) ? $movement['metadata'] : [];
        $sourceSnapshot = is_array($metadata['sourceSnapshot'] ?? null) ? $metadata['sourceSnapshot'] : [];
        $sourceType = self::stringValue($movement['source_type'] ?? null);
        $sourceId = self::stringValue($movement['source_id'] ?? null);

        if (($metadata['source'] ?? null) === 'clinical_catalog_consumption_recipe') {
            return [
                'key' => InventoryStockMovementSourceKind::CLINICAL_CONSUMPTION->value,
                'label' => self::clinicalConsumptionLabel($sourceType),
                'reference' => self::firstNonEmptyString([
                    $sourceSnapshot['order_number'] ?? null,
                    $sourceSnapshot['procedure_number'] ?? null,
                    $sourceId,
                ]),
                'detail' => self::implodeDetail([
                    self::clinicalCatalogTypeLabel($metadata['catalogType'] ?? null),
                    self::consumptionStageLabel($metadata['consumptionStage'] ?? null),
                ]),
            ];
        }

        if (($metadata['source'] ?? null) === 'warehouse_transfer') {
            return [
                'key' => InventoryStockMovementSourceKind::WAREHOUSE_TRANSFER->value,
                'label' => self::warehouseTransferLabel($metadata['transferStage'] ?? null),
                'reference' => self::firstNonEmptyString([
                    $metadata['transferNumber'] ?? null,
                    $sourceId,
                ]),
                'detail' => self::implodeDetail([
                    self::warehouseTransferStageLabel($metadata['transferStage'] ?? null),
                    self::warehouseRouteLabel(
                        sourceWarehouseId: $metadata['sourceWarehouseId'] ?? null,
                        destinationWarehouseId: $metadata['destinationWarehouseId'] ?? null,
                    ),
                ]),
            ];
        }

        if (($metadata['source'] ?? null) === 'stock_reconciliation') {
            return [
                'key' => InventoryStockMovementSourceKind::STOCK_RECONCILIATION->value,
                'label' => 'Stock reconciliation',
                'reference' => self::firstNonEmptyString([
                    $metadata['sessionReference'] ?? null,
                    $sourceId,
                ]),
                'detail' => null,
            ];
        }

        if (self::stringValue($movement['procurement_request_id'] ?? null) !== null) {
            return [
                'key' => InventoryStockMovementSourceKind::PROCUREMENT_RECEIPT->value,
                'label' => 'Procurement receipt',
                'reference' => self::firstNonEmptyString([
                    $metadata['requestNumber'] ?? null,
                    $metadata['purchaseOrderNumber'] ?? null,
                    $movement['procurement_request_id'] ?? null,
                ]),
                'detail' => null,
            ];
        }

        if (($movement['actor_id'] ?? null) !== null) {
            return [
                'key' => InventoryStockMovementSourceKind::MANUAL_ENTRY->value,
                'label' => 'Manual ledger entry',
                'reference' => null,
                'detail' => null,
            ];
        }

        return [
            'key' => InventoryStockMovementSourceKind::SYSTEM_GENERATED->value,
            'label' => 'System-generated movement',
            'reference' => $sourceId,
            'detail' => $sourceType !== null ? Str::headline(str_replace('_', ' ', $sourceType)) : null,
        ];
    }

    private static function clinicalConsumptionLabel(?string $sourceType): string
    {
        return match ($sourceType) {
            'laboratory_order' => 'Lab test completion',
            'radiology_order' => 'Radiology completion',
            'theatre_procedure' => 'Theatre procedure completion',
            default => 'Clinical consumption',
        };
    }

    private static function warehouseTransferLabel(mixed $stage): string
    {
        return match (self::stringValue($stage)) {
            'dispatch' => 'Warehouse transfer dispatch',
            'receipt' => 'Warehouse transfer receipt',
            default => 'Warehouse transfer',
        };
    }

    private static function warehouseTransferStageLabel(mixed $stage): ?string
    {
        $normalized = self::stringValue($stage);

        return $normalized === null ? null : 'Stage: '.Str::headline($normalized);
    }

    private static function warehouseRouteLabel(mixed $sourceWarehouseId, mixed $destinationWarehouseId): ?string
    {
        $source = self::stringValue($sourceWarehouseId);
        $destination = self::stringValue($destinationWarehouseId);

        if ($source === null && $destination === null) {
            return null;
        }

        if ($source !== null && $destination !== null) {
            return "Route: {$source} -> {$destination}";
        }

        return $source !== null ? "Source warehouse: {$source}" : "Destination warehouse: {$destination}";
    }

    private static function clinicalCatalogTypeLabel(mixed $value): ?string
    {
        return match (self::stringValue($value)) {
            'formulary_item' => 'Approved medicines',
            'lab_test' => 'Lab tests',
            'radiology_procedure' => 'Radiology',
            'theatre_procedure' => 'Theatre',
            default => null,
        };
    }

    private static function consumptionStageLabel(mixed $value): ?string
    {
        $stage = self::stringValue($value);

        if ($stage === null) {
            return null;
        }

        return 'Stage: '.Str::headline(str_replace('_', ' ', $stage));
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private static function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            $normalized = self::stringValue($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<int, ?string>  $parts
     */
    private static function implodeDetail(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, static fn (?string $value): bool => $value !== null && trim($value) !== ''));

        return $parts === [] ? null : implode(' | ', $parts);
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
