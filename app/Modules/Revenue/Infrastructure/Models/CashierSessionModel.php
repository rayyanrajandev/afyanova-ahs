<?php

namespace App\Modules\Revenue\Infrastructure\Models;

use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierSessionModel extends Model
{
    use HasUuids;

    protected $table = 'cashier_sessions';

    protected $guarded = [];

    protected $casts = [
        'status' => CashierSessionStatus::class,
        'opening_float_minor' => 'integer',
        'declared_cash_minor' => 'integer',
        'expected_cash_minor' => 'integer',
        'variance_minor' => 'integer',
        'opened_at' => 'datetime',
        'counted_at' => 'datetime',
        'closed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentModel::class, 'cashier_session_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashierSessionMovementModel::class, 'cashier_session_id');
    }

    public function openingFloat(): Money
    {
        return Money::of((int) $this->opening_float_minor, (string) $this->currency_code);
    }
}
