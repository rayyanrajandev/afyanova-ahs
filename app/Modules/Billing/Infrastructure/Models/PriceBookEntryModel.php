<?php

namespace App\Modules\Billing\Infrastructure\Models;

use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceBookEntryModel extends Model
{
    use HasUuids;

    protected $table = 'price_book_entries';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'chargeable_item_id',
        'tenant_id',
        'facility_id',
        'facility_tier',
        'payer_contract_id',
        'currency_code',
        'unit_price',
        'tax_rate_percent',
        'is_taxable',
        'effective_from',
        'effective_to',
        'tariff_version',
        'supersedes_price_book_entry_id',
        'status',
        'status_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'tax_rate_percent' => 'decimal:2',
            'is_taxable' => 'boolean',
            'tariff_version' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function chargeableItem(): BelongsTo
    {
        return $this->belongsTo(ChargeableItemModel::class, 'chargeable_item_id');
    }
}
