<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Read projection written exclusively by SyncBillingSourceStatusProjection.
 * See billing-financial-state-remediation-plan.md, Phase 2.
 */
class BillingSourceStatusModel extends Model
{
    use HasUuids;

    protected $table = 'billing_source_status';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'source_workflow_kind',
        'source_workflow_id',
        'status',
        'billing_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
