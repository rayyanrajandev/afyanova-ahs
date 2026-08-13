<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingDailyCloseModel extends Model
{
    use HasUuids;

    protected $table = 'billing_daily_closes';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'closed_by_user_id',
        'closed_at',
        'opened_at',
        'total_cash_amount',
        'total_card_amount',
        'total_mpesa_amount',
        'total_other_amount',
        'total_revenue',
        'total_refunds',
        'net_revenue',
        'notes',
        'status',
        'verified_by_user_id',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'opened_at' => 'datetime',
            'total_cash_amount' => 'decimal:2',
            'total_card_amount' => 'decimal:2',
            'total_mpesa_amount' => 'decimal:2',
            'total_other_amount' => 'decimal:2',
            'total_revenue' => 'decimal:2',
            'total_refunds' => 'decimal:2',
            'net_revenue' => 'decimal:2',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
