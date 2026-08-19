<?php

namespace App\Modules\Revenue\Infrastructure\Models;

use App\Modules\Revenue\Domain\ValueObjects\CashMovementReason;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashierSessionMovementModel extends Model
{
    use HasUuids;

    protected $table = 'cashier_session_movements';

    protected $guarded = [];

    protected $casts = [
        'reason' => CashMovementReason::class,
        'amount_minor' => 'integer',
        'occurred_at' => 'datetime',
    ];
}
