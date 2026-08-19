<?php

namespace App\Modules\Revenue\Infrastructure\Models;

use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\RefundStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundModel extends Model
{
    use HasUuids;

    protected $table = 'refunds';

    protected $guarded = [];

    protected $casts = [
        'status' => RefundStatus::class,
        'amount_minor' => 'integer',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(PaymentModel::class, 'original_payment_id');
    }

    public function amount(): Money
    {
        return Money::of((int) $this->amount_minor, (string) $this->currency_code);
    }
}
