<?php

namespace App\Modules\Platform\Infrastructure\Models;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalCatalogConsumptionRecipeItemModel extends Model
{
    use HasUuids;

    protected $table = 'clinical_catalog_consumption_recipe_items';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'clinical_catalog_item_id',
        'inventory_item_id',
        'quantity_per_order',
        'unit',
        'waste_factor_percent',
        'consumption_stage',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_per_order' => 'decimal:4',
            'waste_factor_percent' => 'decimal:2',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function clinicalCatalogItem(): BelongsTo
    {
        return $this->belongsTo(ClinicalCatalogItemModel::class, 'clinical_catalog_item_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItemModel::class, 'inventory_item_id');
    }
}
