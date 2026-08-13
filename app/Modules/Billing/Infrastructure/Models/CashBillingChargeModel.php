<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashBillingChargeModel extends Model
{
    use HasUuids;

    protected $table = 'cash_billing_charges';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'cash_billing_account_id',
        'service_id',
        'service_name',
        'quantity',
        'unit_price',
        'charge_amount',
        'recorded_by_user_id',
        'charge_date',
        'reference_id',
        'reference_type',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'charge_amount' => 'decimal:2',
            'charge_date' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the cash billing account this charge belongs to
     */
    public function cashBillingAccount(): BelongsTo
    {
        return $this->belongsTo(CashBillingAccountModel::class, 'cash_billing_account_id', 'id');
    }
}
