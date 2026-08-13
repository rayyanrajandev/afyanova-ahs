<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Domain\Repositories\PosCafeteriaMenuItemRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosCatalogItemStatus;

class UpdatePosCafeteriaMenuItemUseCase
{
    public function __construct(
        private readonly PosCafeteriaMenuItemRepositoryInterface $posCafeteriaMenuItemRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->posCafeteriaMenuItemRepository->findById($id);
        if ($existing === null) {
            return null;
        }

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $itemCode = array_key_exists('item_code', $payload)
            ? $this->normalizeItemCode($payload['item_code'])
            : ($existing['item_code'] ?? null);

        if (
            $itemCode !== null
            && $this->posCafeteriaMenuItemRepository->existsByItemCodeInScope($itemCode, $tenantId, $facilityId, $id)
        ) {
            throw new PosOperationException(
                'Menu item code already exists for the current scope.',
                'itemCode',
            );
        }

        $attributes = [
            'updated_by_user_id' => $actorId,
        ];

        if (array_key_exists('item_code', $payload)) {
            $attributes['item_code'] = $itemCode;
        }

        if (array_key_exists('item_name', $payload)) {
            $attributes['item_name'] = trim((string) $payload['item_name']);
        }

        if (array_key_exists('category', $payload)) {
            $attributes['category'] = $this->nullableTrimmedValue($payload['category']);
        }

        if (array_key_exists('unit_label', $payload)) {
            $attributes['unit_label'] = $this->nullableTrimmedValue($payload['unit_label']);
        }

        if (array_key_exists('unit_price', $payload)) {
            $attributes['unit_price'] = $this->normalizeMoney($payload['unit_price']);
        }

        if (array_key_exists('tax_rate_percent', $payload)) {
            $attributes['tax_rate_percent'] = $this->normalizePercent($payload['tax_rate_percent']);
        }

        if (array_key_exists('status', $payload)) {
            $attributes['status'] = $this->resolveStatus($payload['status']);
        }

        if (array_key_exists('status_reason', $payload)) {
            $attributes['status_reason'] = $this->nullableTrimmedValue($payload['status_reason']);
        }

        if (array_key_exists('sort_order', $payload)) {
            $attributes['sort_order'] = max((int) $payload['sort_order'], 0);
        }

        if (array_key_exists('description', $payload)) {
            $attributes['description'] = $this->nullableTrimmedValue($payload['description']);
        }

        if (array_key_exists('metadata', $payload)) {
            $attributes['metadata'] = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null;
        }

        return $this->posCafeteriaMenuItemRepository->update($id, $attributes);
    }

    private function resolveStatus(mixed $value): string
    {
        $status = strtolower(trim((string) $value));

        return in_array($status, PosCatalogItemStatus::values(), true)
            ? $status
            : PosCatalogItemStatus::ACTIVE->value;
    }

    private function normalizeItemCode(mixed $value): ?string
    {
        $normalized = strtoupper(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeMoney(mixed $value): float
    {
        return round(max((float) $value, 0), 2);
    }

    private function normalizePercent(mixed $value): float
    {
        return round(max((float) $value, 0), 2);
    }
}
