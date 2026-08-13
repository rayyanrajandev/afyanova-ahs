<?php

namespace App\Modules\ServiceRequest\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequestAuditEventModel extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'service_request_audit_events';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'service_request_id',
        'service_request_item_id',
        'actor_user_id',
        'action',
        'from_status',
        'to_status',
        'metadata',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequestModel::class, 'service_request_id');
    }
}
