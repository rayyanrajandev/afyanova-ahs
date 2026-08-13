<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Domain\Repositories\PosCafeteriaMenuItemRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosCatalogItemStatus;
use App\Modules\Pos\Domain\ValueObjects\PosSaleChannel;
use App\Modules\Pos\Domain\ValueObjects\PosSaleLineType;

class CreateCafeteriaPosSaleUseCase
{
    public function __construct(
        private readonly PosCafeteriaMenuItemRepositoryInterface $posCafeteriaMenuItemRepository,
        private readonly CreatePosSaleUseCase $createPosSaleUseCase,
    ) {}

    public function execute(array $payload, ?int $actorId = null): ?array
    {
        $normalizedItems = $this->normalizeItems($payload['items'] ?? []);

        return $this->createPosSaleUseCase->execute(
            payload: [
                'pos_register_id' => $payload['pos_register_id'],
                'patient_id' => null,
                'customer_name' => $payload['customer_name'] ?? null,
                'customer_reference' => $payload['customer_reference'] ?? null,
                'currency_code' => $payload['currency_code'] ?? null,
                'sale_channel' => PosSaleChannel::CAFETERIA->value,
                'sold_at' => $payload['sold_at'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'metadata' => array_merge(
                    is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                    [
                        'source' => 'cafeteria_pos',
                        'menuItemCount' => count($normalizedItems),
                        'categories' => array_values(array_unique(array_values(array_filter(array_map(
                            static fn (array $item): string => trim((string) ($item['menu_item']['category'] ?? '')),
                            $normalizedItems,
                        ))))),
                    ],
                ),
                'line_items' => array_map(
                    function (array $item): array {
                        return [
                            'item_type' => PosSaleLineType::CAFETERIA_ITEM->value,
                            'item_reference' => (string) ($item['menu_item']['id'] ?? ''),
                            'item_code' => $item['menu_item']['item_code'] ?? null,
                            'item_name' => $item['menu_item']['item_name'] ?? 'Cafeteria item',
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['unit_price'],
                            'discount_amount' => 0,
                            'tax_amount' => $item['tax_amount'],
                            'notes' => $item['notes'],
                            'metadata' => [
                                'source' => 'cafeteria_pos',
                                'cafeteriaMenuItemId' => $item['menu_item']['id'] ?? null,
                                'category' => $item['menu_item']['category'] ?? null,
                                'unitLabel' => $item['menu_item']['unit_label'] ?? null,
                                'taxRatePercent' => $item['tax_rate_percent'],
                            ],
                        ];
                    },
                    $normalizedItems,
                ),
                'payments' => $payload['payments'] ?? [],
            ],
            actorId: $actorId,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        if ($items === []) {
            throw new PosOperationException('At least one cafeteria item is required.', 'items');
        }

        $menuItemIds = array_map(
            static fn (array $item): string => trim((string) ($item['menu_item_id'] ?? '')),
            array_values($items),
        );
        $menuItems = $this->posCafeteriaMenuItemRepository->findByIds($menuItemIds);
        $menuItemMap = [];

        foreach ($menuItems as $menuItem) {
            $menuItemMap[(string) ($menuItem['id'] ?? '')] = $menuItem;
        }

        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            $menuItemId = trim((string) ($item['menu_item_id'] ?? ''));
            if ($menuItemId === '') {
                throw new PosOperationException(
                    'Select one cafeteria menu item for each line.',
                    "items.$index.menuItemId",
                );
            }

            $menuItem = $menuItemMap[$menuItemId] ?? null;
            if ($menuItem === null) {
                throw new PosOperationException(
                    'Select an existing cafeteria menu item before checkout.',
                    "items.$index.menuItemId",
                );
            }

            if (($menuItem['status'] ?? null) !== PosCatalogItemStatus::ACTIVE->value) {
                throw new PosOperationException(
                    'Inactive cafeteria menu items cannot be sold.',
                    "items.$index.menuItemId",
                );
            }

            $quantity = round(max((float) ($item['quantity'] ?? 0), 0), 2);
            if ($quantity <= 0) {
                throw new PosOperationException(
                    'Cafeteria quantity must be greater than zero.',
                    "items.$index.quantity",
                );
            }

            $unitPrice = round(max((float) ($menuItem['unit_price'] ?? 0), 0), 2);
            if ($unitPrice <= 0) {
                throw new PosOperationException(
                    'Active cafeteria menu items must have a positive price before checkout.',
                    "items.$index.menuItemId",
                );
            }

            $taxRatePercent = round(max((float) ($menuItem['tax_rate_percent'] ?? 0), 0), 2);
            $lineSubtotalAmount = round($quantity * $unitPrice, 2);
            $taxAmount = round($lineSubtotalAmount * ($taxRatePercent / 100), 2);

            $normalized[] = [
                'menu_item' => $menuItem,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate_percent' => $taxRatePercent,
                'tax_amount' => $taxAmount,
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
