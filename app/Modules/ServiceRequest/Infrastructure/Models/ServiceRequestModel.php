<?php

namespace App\Modules\ServiceRequest\Infrastructure\Models;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRequestModel extends Model
{
    use HasUuids;

    protected $table = 'service_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'request_number',
        'tenant_id',
        'facility_id',
        'patient_id',
        'appointment_id',
        'encounter_id',
        'department_id',
        'requested_by_user_id',
        'service_type',
        'priority',
        'status',
        'notes',
        'requested_at',
        'acknowledged_at',
        'acknowledged_by_user_id',
        'assessed_by_user_id',
        'assessed_at',
        'completed_at',
        'status_reason',
        'linked_order_type',
        'linked_order_id',
        'linked_order_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'assessed_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(DepartmentModel::class, 'department_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceRequestItemModel::class, 'service_request_id');
    }
}
