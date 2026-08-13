<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tracks async generation of billing reports (aging report today) too large
 * to bucket and export safely in-request — see GetBillingAgingReportUseCase's
 * unbounded ->get() over every open invoice. Kept separate from
 * AuditExportJobModel (Platform module) since a report export isn't tied to
 * one target resource the way an audit-log export is, and reusing that model
 * would mix an unrelated job type into its existing health dashboards.
 */
class BillingReportExportJobModel extends Model
{
    use HasUuids;

    protected $table = 'billing_report_export_jobs';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'report_type',
        'filters',
        'status',
        'file_path',
        'file_name',
        'row_count',
        'created_by_user_id',
        'started_at',
        'completed_at',
        'failed_at',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'row_count' => 'integer',
            'created_by_user_id' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
