<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Domain\Repositories\PosCafeteriaMenuItemRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosCatalogItemStatus;

class CreatePosCafeteriaMenuItemUseCase
{
    public function __construct(
        private readonly PosCafeteriaMenuItemRepositoryInterface $posCafeteriaMenuItemRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $itemCode = $this->normalizeItemCode($payload['item_code'] ?? null);

        if (
            $itemCode !== null
            && $this->posCafeteriaMenuItemRepository->existsByItemCodeInScope($itemCode, $tenantId, $facilityId)
        ) {
            throw new PosOperationException(
                'Menu item code already exists for the current scope.',
                'itemCode',
            );
        }

        return $this->posCafeteriaMenuItemRepository->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'item_code' => $itemCode,
            'item_name' => trim((string) $payload['item_name']),
            'category' => $this->nullableTrimmedValue($payload['category'] ?? null),
            'unit_label' => $this->nullableTrimmedValue($payload['unit_label'] ?? null),
            'unit_price' => $this->normalizeMoney($payload['unit_price'] ?? 0),
            'tax_rate_percent' => $this->normalizePercent($payload['tax_rate_percent'] ?? 0),
            'status' => $this->resolveStatus($payload['status'] ?? null),
            'status_reason' => $this->nullableTrimmedValue($payload['status_reason'] ?? null),
            'sort_order' => max((int) ($payload['sort_order'] ?? 0), 0),
            'description' => $this->nullableTrimmedValue($payload['description'] ?? null),
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
            'created_by_user_id' => $actorId,
            'updated_by_user_id' => $actorId,
        ]);
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
