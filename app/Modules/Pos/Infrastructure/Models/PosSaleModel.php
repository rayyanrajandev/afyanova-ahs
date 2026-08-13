<?php

namespace App\Modules\Pos\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSaleModel extends Model
{
    use HasUuids;

    protected $table = 'pos_sales';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'pos_register_id',
        'pos_register_session_id',
        'patient_id',
        'sale_number',
        'receipt_number',
        'sale_channel',
        'customer_type',
        'customer_name',
        'customer_reference',
        'currency_code',
        'status',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'balance_amount',
        'change_amount',
        'sold_at',
        'completed_by_user_id',
        'notes',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'sold_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegisterModel::class, 'pos_register_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosRegisterSessionModel::class, 'pos_register_session_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(PosSaleLineModel::class, 'pos_sale_id')->orderBy('line_number');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PosSalePaymentModel::class, 'pos_sale_id')->orderBy('paid_at');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PosSaleAdjustmentModel::class, 'pos_sale_id')->orderBy('processed_at');
    }
}
