<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingRefundAuditLogModel extends Model
{
    use HasUuids;

    protected $table = 'billing_refund_audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'billing_refund_id',
        'action',
        'actor_id',
        'actor_name',
        'notes',
    ];

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

    /**
     * Get the refund this log entry is for
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(BillingRefundModel::class, 'billing_refund_id', 'id');
    }
}
