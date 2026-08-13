<?php

namespace App\Modules\Laboratory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LaboratoryOrderModel extends Model
{
    use HasUuids;

    protected $table = 'laboratory_orders';

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
        'lab_test_catalog_item_id',
        'test_code',
        'test_name',
        'priority',
        'specimen_type',
        'clinical_notes',
        'result_summary',
        'result_parameters',
        'resulted_at',
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
            'result_parameters' => 'array',
            'ordered_at' => 'datetime',
            'resulted_at' => 'datetime',
            'verified_at' => 'datetime',
            'signed_at' => 'datetime',
            'entered_in_error_at' => 'datetime',
            'lifecycle_locked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
