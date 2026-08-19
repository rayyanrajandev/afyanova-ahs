<?php

namespace App\Modules\Revenue\Infrastructure\Models;

use App\Modules\Revenue\Domain\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptModel extends Model
{
    use HasUuids;

    protected $table = 'receipts';

    protected $guarded = [];

    protected $casts = [
        'total_minor' => 'integer',
        'snapshot' => 'array',
        'issued_at' => 'datetime',
        'fiscal_issued_at' => 'datetime',
        'last_reprinted_at' => 'datetime',
        'reprint_count' => 'integer',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentModel::class, 'payment_id');
    }

    public function total(): Money
    {
        return Money::of((int) $this->total_minor, (string) $this->currency_code);
    }
}
