<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InsufficientInventoryStockException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\Pharmacy\Domain\Services\ApprovedMedicineCatalogLookupServiceInterface;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Application\Support\PharmacyOtcCatalogSupport;
use App\Modules\Pos\Domain\ValueObjects\PosSaleChannel;
use App\Modules\Pos\Domain\ValueObjects\PosSaleLineType;
use Illuminate\Support\Facades\DB;

class CreatePharmacyOtcSaleUseCase
{
    public function __construct(
        private readonly ApprovedMedicineCatalogLookupServiceInterface $approvedMedicineCatalogLookupService,
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
        private readonly CreatePosSaleUseCase $createPosSaleUseCase,
        private readonly PharmacyOtcCatalogSupport $pharmacyOtcCatalogSupport,
    ) {}

    public function execute(array $payload, ?int $actorId = null): ?array
    {
        return DB::transaction(function () use ($payload, $actorId): ?array {
            $normalizedItems = $this->normalizeItems($payload['items'] ?? []);
            $sale = $this->createPosSaleUseCase->execute(
                payload: [
                    'pos_register_id' => $payload['pos_register_id'],
                    'patient_id' => $payload['patient_id'] ?? null,
                    'customer_name' => $payload['customer_name'] ?? null,
                    'customer_reference' => $payload['customer_reference'] ?? null,
                    'currency_code' => $payload['currency_code'] ?? null,
                    'sale_channel' => PosSaleChannel::PHARMACY_OTC->value,
                    'sold_at' => $payload['sold_at'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'metadata' => array_merge(
                        is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                        [
                            'source' => 'pharmacy_otc',
                            'catalogItemCount' => count($normalizedItems),
                        ],
                    ),
                    'line_items' => array_map(
                        function (array $item): array {
                            return [
                                'item_type' => PosSaleLineType::PHARMACY_ITEM->value,
                                'item_reference' => (string) $item['inventory_item']['id'],
                                'item_code' => $item['catalog_item']['code'] ?? null,
                                'item_name' => $item['catalog_item']['name'] ?? 'Approved medicine',
                                'quantity' => $item['quantity'],
                                'unit_price' => $item['unit_price'],
                                'discount_amount' => $item['discount_amount'],
                                'tax_amount' => $item['tax_amount'],
                                'notes' => $item['notes'],
                                'metadata' => [
                                    'source' => 'pharmacy_otc',
                                    'approvedMedicineCatalogItemId' => $item['catalog_item']['id'] ?? null,
                                    'inventoryItemId' => $item['inventory_item']['id'] ?? null,
                                    'reviewMode' => $item['otc_context']['reviewMode'] ?? null,
                                    'dosageForm' => $item['otc_context']['dosageForm'] ?? null,
                                    'strength' => $item['otc_context']['strength'] ?? null,
                                    'priceSource' => $item['price_source'],
                                ],
                            ];
                        },
                        $normalizedItems,
                    ),
                    'payments' => $payload['payments'] ?? [],
                ],
                actorId: $actorId,
            );

            if ($sale === null) {
                return null;
            }

            $saleLineItems = array_values(is_array($sale['line_items'] ?? null) ? $sale['line_items'] : []);
            $stockMovements = [];

            foreach ($normalizedItems as $index => $item) {
                $saleLine = $saleLineItems[$index] ?? null;

                try {
                    $stockMovements[] = $this->inventoryBatchStockService->issue([
                        'item_id' => $item['inventory_item']['id'],
                        'source_warehouse_id' => $item['inventory_item']['default_warehouse_id'] ?? null,
                        'unit_id' => $item['unit_id'] ?? null,
                        'unit' => $item['unit'] ?? null,
                        'quantity' => $item['quantity'],
                        'reason' => 'pharmacy_otc_sale',
                        'notes' => sprintf(
                            'Issued through Pharmacy OTC POS sale %s.',
                            $sale['sale_number'] ?? 'POS sale',
                        ),
                        'metadata' => [
                            'source' => 'pos.pharmacy_otc',
                            'pos_sale_id' => $sale['id'] ?? null,
                            'pos_sale_number' => $sale['sale_number'] ?? null,
                            'pos_receipt_number' => $sale['receipt_number'] ?? null,
                            'pos_sale_line_id' => $saleLine['id'] ?? null,
                            'approved_medicine_catalog_item_id' => $item['catalog_item']['id'] ?? null,
                        ],
                        'occurred_at' => $payload['sold_at'] ?? now(),
                    ], $actorId);
                } catch (InventoryItemNotFoundException) {
                    throw new PosOperationException(
                        'Inventory linkage for the selected OTC medicine could not be resolved.',
                        "items.$index.catalogItemId",
                    );
                } catch (InsufficientInventoryStockException) {
                    throw new PosOperationException(
                        'Insufficient stock remains for the requested OTC quantity.',
                        "items.$index.quantity",
                    );
                }
            }

            $sale['stock_movements'] = $stockMovements;

            return $sale;
        });
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        if ($items === []) {
            throw new PosOperationException('At least one OTC medicine is required.', 'items');
        }

        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            $catalogItemId = trim((string) ($item['catalog_item_id'] ?? ''));
            if ($catalogItemId === '') {
                throw new PosOperationException(
                    'Select one approved medicine for each OTC line.',
                    "items.$index.catalogItemId",
                );
            }

            $catalogItem = $this->approvedMedicineCatalogLookupService->findActiveById($catalogItemId);
            if ($catalogItem === null) {
                throw new PosOperationException(
                    'Select an active approved medicine before checking out.',
                    "items.$index.catalogItemId",
                );
            }

            $otcContext = $this->pharmacyOtcCatalogSupport->otcContext($catalogItem);
            if (! ($otcContext['otcEligible'] ?? false)) {
                throw new PosOperationException(
                    (string) ($otcContext['otcEligibilityReason'] ?? 'The selected medicine is not eligible for OTC sale.'),
                    "items.$index.catalogItemId",
                );
            }

            $inventoryItem = $this->inventoryItemRepository->findBestActiveMatchByCodeOrName(
                $catalogItem['code'] ?? null,
                $catalogItem['name'] ?? null,
            );
            if ($inventoryItem === null) {
                throw new PosOperationException(
                    'No active inventory item matches this approved medicine in the current facility scope.',
                    "items.$index.catalogItemId",
                );
            }

            $availability = $this->inventoryBatchStockService->availability(
                (string) $inventoryItem['id'],
                now(),
                $inventoryItem['default_warehouse_id'] ?? null,
            );

            $quantity = round(max((float) ($item['quantity'] ?? 0), 0), 2);
            if ($quantity <= 0) {
                throw new PosOperationException(
                    'OTC quantity must be greater than zero.',
                    "items.$index.quantity",
                );
            }

            if ((float) ($availability['availableQuantity'] ?? 0) < $quantity) {
                throw new PosOperationException(
                    'Insufficient stock remains for the requested OTC quantity.',
                    "items.$index.quantity",
                );
            }

            $pricing = $this->pharmacyOtcCatalogSupport->resolveUnitPrice(
                catalogItem: $catalogItem,
                requestedValue: $item['unit_price'] ?? null,
                field: "items.$index.unitPrice",
            );

            $normalized[] = [
                'catalog_item' => $catalogItem,
                'inventory_item' => array_merge($inventoryItem, [
                    'available_stock' => $availability['availableQuantity'],
                    'stock_state' => $availability['stockState'],
                    'batch_tracking_mode' => $availability['trackingMode'],
                    'blocked_batch_quantity' => $availability['blockedQuantity'],
                ]),
                'otc_context' => $otcContext,
                'quantity' => $quantity,
                'unit_id' => $item['unit_id'] ?? null,
                'unit' => $item['unit'] ?? null,
                'unit_price' => $pricing['unitPrice'],
                'price_source' => $pricing['source'],
                'discount_amount' => round(max((float) ($item['discount_amount'] ?? 0), 0), 2),
                'tax_amount' => round(max((float) ($item['tax_amount'] ?? 0), 0), 2),
                'notes' => $this->nullableTrimmedValue($item['notes'] ?? null),
            ];
        }

        return $normalized;
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
