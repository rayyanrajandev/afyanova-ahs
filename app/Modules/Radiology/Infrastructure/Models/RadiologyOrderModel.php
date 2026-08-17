<?php

namespace App\Modules\Radiology\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RadiologyOrderModel extends Model
{
    use HasUuids;

    protected $table = 'radiology_orders';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'service_request_item_id',
        'order_number',
        'tenant_id',
        'facility_id',
        'patient_id',
        'encounter_id',
        'admission_id',
        'appointment_id',
        'clinical_order_session_id',
        'replaces_order_id',
        'add_on_to_order_id',
        'ordered_by_user_id',
        'ordered_at',
        'radiology_procedure_catalog_item_id',
        'procedure_code',
        'modality',
        'study_description',
        'clinical_indication',
        'scheduled_for',
        'report_summary',
        'completed_at',
        'verified_at',
        'verified_by_user_id',
        'verification_note',
        'status',
        'entry_state',
        'signed_at',
        'signed_by_user_id',
        'status_reason',
        'lifecycle_reason_code',
        'entered_in_error_at',
        'entered_in_error_by_user_id',
        'lifecycle_locked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
            'signed_at' => 'datetime',
            'entered_in_error_at' => 'datetime',
            'lifecycle_locked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
