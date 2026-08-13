<?php

namespace App\Modules\Staff\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceUserMapping extends Model
{
    protected $table = 'device_user_mappings';

    protected $fillable = [
        'device_id',
        'device_user_id',
        'name',
        'staff_id',
    ];

    protected function casts(): array
    {
        return [
            'device_user_id' => 'integer',
        ];
    }
}
