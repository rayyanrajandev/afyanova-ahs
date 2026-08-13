<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilityResourceModel extends Model
{
    use HasUuids;

    protected $table = 'facility_resources';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'resource_type',
        'code',
        'name',
        'department_id',
        'service_point_type',
        'ward_name',
        'bed_number',
        'gender_restriction',
        'location',
        'status',
        'status_reason',
        'notes',
        'chargeable_item_id',
    ];

    public function chargeableItem(): BelongsTo
    {
        return $this->belongsTo(ChargeableItemModel::class, 'chargeable_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

