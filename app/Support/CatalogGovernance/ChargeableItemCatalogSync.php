<?php

namespace App\Support\CatalogGovernance;

use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;

/**
 * Closes the gap PricingEngine's rollout found: a clinical catalog item
 * could exist with no chargeable_items counterpart at all until someone
 * remembered to run `pricing:backfill-chargeable-items` by hand. This makes
 * that structurally impossible going forward -- every clinical catalog
 * item write (via EloquentClinicalCatalogItemRepository, the single choke
 * point every catalog admin screen already goes through) ensures a linked
 * chargeable_items row exists in the same request, using the same id-reuse
 * convention the backfill command established. It does NOT create a price
 * (price_book_entries) -- pricing a new item is a deliberate decision for
 * a human to make via the chargeable-items admin screen, not something to
 * fabricate automatically. An unpriced item safely falls back to the
 * legacy price via ListBillingChargeCaptureCandidatesUseCase's fallback
 * until someone sets one.
 */
class ChargeableItemCatalogSync
{
    public function sync(ClinicalCatalogItemModel $catalogItem): void
    {
        $existing = ChargeableItemModel::query()->find($catalogItem->id);

        $attributes = [
            'clinical_catalog_item_id' => $catalogItem->id,
            'tenant_id' => $catalogItem->tenant_id,
            'facility_id' => $catalogItem->facility_id,
            'facility_tier' => $catalogItem->facility_tier,
            'catalog_type' => $catalogItem->catalog_type,
            'code' => $catalogItem->code,
            'name' => $catalogItem->name,
            'department_id' => $catalogItem->department_id,
            'category' => $catalogItem->category,
            'default_unit' => $catalogItem->unit,
            'status' => $catalogItem->status,
            'status_reason' => $catalogItem->status_reason,
            'metadata' => $catalogItem->metadata,
        ];

        if ($existing !== null) {
            // charge_model is a deliberate pricing decision that may have
            // been changed by a finance admin -- never overwrite it here,
            // only keep identity/status fields current for anything that
            // still reads the stored column directly rather than through
            // the relation (e.g. sort order, exports).
            unset($attributes['catalog_type']);
            $existing->fill($attributes);
            $existing->save();

            return;
        }

        $chargeableItem = new ChargeableItemModel();
        $chargeableItem->id = $catalogItem->id;
        $chargeableItem->fill([
            ...$attributes,
            'charge_model' => $this->deriveChargeModel((string) $catalogItem->catalog_type),
        ]);
        $chargeableItem->save();
    }

    public function deriveChargeModel(string $catalogType): string
    {
        return match ($catalogType) {
            ClinicalCatalogType::FORMULARY_ITEM->value => 'per_unit',
            default => 'flat',
        };
    }
}
