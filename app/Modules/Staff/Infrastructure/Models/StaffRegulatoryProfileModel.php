<?php

namespace App\Modules\Staff\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StaffRegulatoryProfileModel extends Model
{
    use HasUuids;

    protected $table = 'staff_regulatory_profiles';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'staff_profile_id',
        'tenant_id',
        'primary_regulator_code',
        'cadre_code',
        'professional_title',
        'registration_type',
        'practice_authority_level',
        'supervision_level',
        'good_standing_status',
        'good_standing_checked_at',
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
            'good_standing_checked_at' => 'date',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
