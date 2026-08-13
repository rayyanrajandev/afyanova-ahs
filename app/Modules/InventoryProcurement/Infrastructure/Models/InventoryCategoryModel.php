<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inventory_MasterData_Alignment_Plan.md Phase 5. Configurable master-data
 * mirror of the InventoryItemCategory enum's behavior flags. The enum is
 * still the Domain-layer source of truth for dynamic-rendering logic
 * (formTemplate(), requiresColdChain(), etc.) -- this table is what makes
 * "which categories exist" a database row instead of a code deploy.
 */
class InventoryCategoryModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_categories';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'label',
        'form_template',
        'description',
        'requires_expiry_tracking',
        'requires_cold_chain',
        'controlled_substance_eligible',
        'supports_medicine_details',
        'supports_storage_fields',
        'supports_clinical_classification',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_expiry_tracking' => 'boolean',
            'requires_cold_chain' => 'boolean',
            'controlled_substance_eligible' => 'boolean',
            'supports_medicine_details' => 'boolean',
            'supports_storage_fields' => 'boolean',
            'supports_clinical_classification' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(InventorySubcategoryModel::class, 'category_id');
    }
}
