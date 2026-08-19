<?php

namespace App\Modules\Revenue\Infrastructure\Models;

use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChargeWaiverModel extends Model
{
    use HasUuids;

    protected $table = 'charge_waivers';

    protected $guarded = [];

    protected $casts = [
        'basis' => AuthorizationBasis::class,
        'amount_minor' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function serviceCharge(): BelongsTo
    {
        return $this->belongsTo(ServiceChargeModel::class, 'service_charge_id');
    }
}
