<?php

namespace App\Modules\Revenue\Infrastructure\Models;

use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\PaymentMethod;
use App\Modules\Revenue\Domain\ValueObjects\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentModel extends Model
{
    use HasUuids;

    protected $table = 'payments';

    protected $guarded = [];

    protected $casts = [
        'method' => PaymentMethod::class,
        'status' => PaymentStatus::class,
        'amount_minor' => 'integer',
        'tendered_amount_minor' => 'integer',
        'change_amount_minor' => 'integer',
        'allocated_amount_minor' => 'integer',
        'received_at' => 'datetime',
        'reversed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocationModel::class, 'payment_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(ReceiptModel::class, 'payment_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashierSessionModel::class, 'cashier_session_id');
    }

    public function amount(): Money
    {
        return Money::of((int) $this->amount_minor, (string) $this->currency_code);
    }
}
