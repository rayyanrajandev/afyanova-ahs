<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Support\CatalogGovernance\InventoryClinicalLinkGuard;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class InventoryItemModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_items';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'item_code',
        'msd_code',
        'nhif_code',
        'barcode',
        'codes',
        'tenant_id',
        'facility_id',
        'clinical_catalog_item_id',
        'default_warehouse_id',
        'default_supplier_id',
        'item_name',
        // generic_name/dosage_form/strength/is_controlled_substance/
        // controlled_substance_schedule dropped in Phase 3 (Inventory_MasterData_Alignment_Plan.md):
        // Pharmaceutical-only, and Pharmaceutical is always catalog-linked, so these
        // are always read through clinicalCatalogItem() now, never stored here.
        'category',
        'subcategory',
        'ven_classification',
        'abc_classification',
        'unit',
        'dispensing_unit',
        'conversion_factor',
        'bin_location',
        'manufacturer',
        // storage_conditions/requires_cold_chain stay: Blood Product, Laboratory, and
        // Food & Nutrition use them and can never catalog-link, so Inventory is the
        // only possible owner for those three categories.
        'storage_conditions',
        'requires_cold_chain',
        'current_stock',
        'reorder_level',
        'max_stock_level',
        'status',
        'status_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'max_stock_level' => 'decimal:3',
            'conversion_factor' => 'decimal:4',
            'requires_cold_chain' => 'boolean',
            'codes' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function clinicalCatalogItem(): BelongsTo
    {
        return $this->belongsTo(ClinicalCatalogItemModel::class, 'clinical_catalog_item_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(InventoryStockMovementModel::class, 'item_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(InventoryItemUnitModel::class, 'item_id');
    }

    public function activeUnits(): HasMany
    {
        return $this->hasMany(InventoryItemUnitModel::class, 'item_id')->where('is_active', true);
    }

    public function unitPrices(): HasMany
    {
        return $this->hasMany(InventoryItemUnitPriceModel::class, 'item_id');
    }

    protected static function booted(): void
    {
        static::saving(function (InventoryItemModel $item): void {
            app(InventoryClinicalLinkGuard::class)->assertModelCanPersist($item);
        });
    }
}
