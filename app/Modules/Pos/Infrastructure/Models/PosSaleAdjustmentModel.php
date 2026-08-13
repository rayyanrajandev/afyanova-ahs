<?php

namespace App\Modules\Pos\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSaleAdjustmentModel extends Model
{
    use HasUuids;

    protected $table = 'pos_sale_adjustments';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'pos_sale_id',
        'pos_register_id',
        'pos_register_session_id',
        'adjustment_number',
        'adjustment_type',
        'amount',
        'cash_amount',
        'non_cash_amount',
        'currency_code',
        'payment_method',
        'adjustment_reference',
        'reason_code',
        'notes',
        'processed_by_user_id',
        'processed_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cash_amount' => 'decimal:2',
            'non_cash_amount' => 'decimal:2',
            'processed_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSaleModel::class, 'pos_sale_id');
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegisterModel::class, 'pos_register_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosRegisterSessionModel::class, 'pos_register_session_id');
    }
}
