<?php

namespace App\Modules\Revenue\Infrastructure\Models;

use App\Modules\Revenue\Domain\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocationModel extends Model
{
    use HasUuids;

    protected $table = 'payment_allocations';

    protected $guarded = [];

    protected $casts = [
        'amount_minor' => 'integer',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentModel::class, 'payment_id');
    }

    public function serviceCharge(): BelongsTo
    {
        return $this->belongsTo(ServiceChargeModel::class, 'service_charge_id');
    }

    public function amount(): Money
    {
        return Money::of((int) $this->amount_minor, (string) $this->currency_code);
    }
}
