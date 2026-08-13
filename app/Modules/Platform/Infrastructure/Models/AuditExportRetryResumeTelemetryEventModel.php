<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditExportRetryResumeTelemetryEventModel extends Model
{
    use HasUuids;

    protected $table = 'audit_export_retry_resume_telemetry_events';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'module_key',
        'event_type',
        'failure_reason',
        'actor_user_id',
        'tenant_id',
        'facility_id',
        'target_resource_id',
        'export_job_id',
        'handoff_status_group',
        'handoff_page',
        'handoff_per_page',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_user_id' => 'integer',
            'handoff_page' => 'integer',
            'handoff_per_page' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
