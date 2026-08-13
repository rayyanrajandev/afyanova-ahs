<?php

namespace App\Modules\Staff\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StaffProfessionalRegistrationModel extends Model
{
    use HasUuids;

    protected $table = 'staff_professional_registrations';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'staff_profile_id',
        'tenant_id',
        'staff_regulatory_profile_id',
        'regulator_code',
        'registration_category',
        'registration_number',
        'license_number',
        'registration_status',
        'license_status',
        'verification_status',
        'verification_reason',
        'verification_notes',
        'verified_at',
        'verified_by_user_id',
        'issued_at',
        'expires_at',
        'renewal_due_at',
        'cpd_cycle_start_at',
        'cpd_cycle_end_at',
        'cpd_points_required',
        'cpd_points_earned',
        'source_document_id',
        'source_system',
        'notes',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'issued_at' => 'date',
            'expires_at' => 'date',
            'renewal_due_at' => 'date',
            'cpd_cycle_start_at' => 'date',
            'cpd_cycle_end_at' => 'date',
            'verified_by_user_id' => 'integer',
            'cpd_points_required' => 'integer',
            'cpd_points_earned' => 'integer',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
