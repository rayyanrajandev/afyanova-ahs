<?php

namespace App\Modules\TheatreProcedure\Infrastructure\Models;

use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TheatreProcedureModel extends Model
{
    use HasUuids;

    protected $table = 'theatre_procedures';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'procedure_number',
        'tenant_id',
        'facility_id',
        'patient_id',
        'encounter_id',
        'admission_id',
        'appointment_id',
        'clinical_order_session_id',
        'replaces_order_id',
        'add_on_to_order_id',
        'theatre_procedure_catalog_item_id',
        'procedure_type',
        'procedure_name',
        'operating_clinician_user_id',
        'anesthetist_user_id',
        'theatre_room_service_point_id',
        'theatre_room_name',
        'scheduled_at',
        'started_at',
        'completed_at',
        'status',
        'entry_state',
        'signed_at',
        'signed_by_user_id',
        'status_reason',
        'lifecycle_reason_code',
        'entered_in_error_at',
        'entered_in_error_by_user_id',
        'lifecycle_locked_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'signed_at' => 'datetime',
            'entered_in_error_at' => 'datetime',
            'lifecycle_locked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientModel::class, 'patient_id');
    }

    public function theatreRoomServicePoint(): BelongsTo
    {
        return $this->belongsTo(FacilityResourceModel::class, 'theatre_room_service_point_id');
    }
}
