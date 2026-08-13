<?php

namespace App\Modules\Pos\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosRegisterSessionModel extends Model
{
    use HasUuids;

    protected $table = 'pos_register_sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'pos_register_id',
        'session_number',
        'status',
        'opened_at',
        'closed_at',
        'opening_cash_amount',
        'closing_cash_amount',
        'expected_cash_amount',
        'discrepancy_amount',
        'gross_sales_amount',
        'total_discount_amount',
        'total_tax_amount',
        'cash_net_sales_amount',
        'non_cash_sales_amount',
        'sale_count',
        'void_count',
        'refund_count',
        'adjustment_amount',
        'cash_adjustment_amount',
        'non_cash_adjustment_amount',
        'opening_note',
        'closing_note',
        'opened_by_user_id',
        'closed_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash_amount' => 'decimal:2',
            'closing_cash_amount' => 'decimal:2',
            'expected_cash_amount' => 'decimal:2',
            'discrepancy_amount' => 'decimal:2',
            'gross_sales_amount' => 'decimal:2',
            'total_discount_amount' => 'decimal:2',
            'total_tax_amount' => 'decimal:2',
            'cash_net_sales_amount' => 'decimal:2',
            'non_cash_sales_amount' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'cash_adjustment_amount' => 'decimal:2',
            'non_cash_adjustment_amount' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegisterModel::class, 'pos_register_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSaleModel::class, 'pos_register_session_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PosSaleAdjustmentModel::class, 'pos_register_session_id');
    }
}
