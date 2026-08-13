<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ClinicalCatalogItemPackagingTemplateModel extends Model
{
    use HasUuids;

    protected $table = 'clinical_catalog_item_packaging_templates';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'clinical_catalog_item_id',
        'unit_name',
        'unit_code',
        'base_quantity',
        'is_base_unit',
        'is_default_purchase_unit',
        'is_default_sales_unit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_quantity' => 'decimal:6',
            'is_base_unit' => 'boolean',
            'is_default_purchase_unit' => 'boolean',
            'is_default_sales_unit' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function clinicalCatalogItem(): BelongsTo
    {
        return $this->belongsTo(ClinicalCatalogItemModel::class, 'clinical_catalog_item_id');
    }
}
