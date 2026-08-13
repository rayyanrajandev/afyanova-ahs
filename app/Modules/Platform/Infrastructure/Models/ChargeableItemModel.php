<?php

namespace App\Modules\Platform\Infrastructure\Models;

use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChargeableItemModel extends Model
{
    use HasUuids;

    protected $table = 'chargeable_items';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'clinical_catalog_item_id',
        'tenant_id',
        'facility_id',
        'facility_tier',
        'catalog_type',
        'charge_model',
        'code',
        'name',
        'department_id',
        'category',
        'default_unit',
        'status',
        'status_reason',
        'metadata',
        'tax_rate_percent',
        'is_taxable',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_taxable' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function priceBookEntries(): HasMany
    {
        return $this->hasMany(PriceBookEntryModel::class, 'chargeable_item_id');
    }

    public function clinicalCatalogItem(): BelongsTo
    {
        return $this->belongsTo(ClinicalCatalogItemModel::class, 'clinical_catalog_item_id');
    }
}
