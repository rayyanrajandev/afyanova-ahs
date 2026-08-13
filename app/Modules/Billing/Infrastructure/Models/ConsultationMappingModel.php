<?php

namespace App\Modules\Billing\Infrastructure\Models;

use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationMappingModel extends Model
{
    protected $table = 'consultation_mappings';

    protected $fillable = [
        'chargeable_item_id',
        'clinician_tier',
        'department',
    ];

    public function chargeableItem(): BelongsTo
    {
        return $this->belongsTo(ChargeableItemModel::class, 'chargeable_item_id');
    }
}
