<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlatformOperationalFlagModel extends Model
{
    use HasUuids;

    protected $table = 'platform_operational_flags';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'facility_id',
        'flag_type',
        'is_active',
        'activated_by_user_id',
        'activated_at',
        'deactivated_at',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active'       => 'boolean',
            'activated_at'    => 'datetime',
            'deactivated_at'  => 'datetime',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
        ];
    }
}
