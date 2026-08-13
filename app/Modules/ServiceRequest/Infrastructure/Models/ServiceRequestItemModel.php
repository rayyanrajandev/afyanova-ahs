<?php

namespace App\Modules\ServiceRequest\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequestItemModel extends Model
{
    use HasUuids;

    protected $table = 'service_request_items';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'service_request_id',
        'service_type',
        'catalog_item_id',
        'reference_pharmacy_order_id',
        'item_name',
        'item_code',
        'quantity',
        'status',
        'clinical_indication',
        'instructions',
        'requested_by_user_id',
        'requested_at',
        'sort_order',
        'ordered_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sort_order' => 'integer',
            'requested_at' => 'datetime',
            'ordered_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequestModel::class, 'service_request_id');
    }
}
