<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashBillingAccountModel extends Model
{
    use HasUuids;

    protected $table = 'cash_billing_accounts';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'currency_code',
        'account_balance',
        'total_charged',
        'total_paid',
        'status',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_balance' => 'decimal:2',
            'total_charged' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get charges for this cash account
     */
    public function charges(): HasMany
    {
        return $this->hasMany(CashBillingChargeModel::class, 'cash_billing_account_id', 'id');
    }

    /**
     * Get payments for this cash account
     */
    public function payments(): HasMany
    {
        return $this->hasMany(CashBillingPaymentModel::class, 'cash_billing_account_id', 'id');
    }
}
