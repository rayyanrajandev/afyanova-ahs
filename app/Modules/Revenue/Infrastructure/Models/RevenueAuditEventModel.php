<?php

namespace App\Modules\Revenue\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. Nothing in the application updates or deletes an audit event;
 * a correction is a further event.
 */
class RevenueAuditEventModel extends Model
{
    use HasUuids;

    protected $table = 'revenue_audit_events';

    protected $guarded = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'before' => 'array',
        'after' => 'array',
        'occurred_at' => 'datetime',
    ];
}
