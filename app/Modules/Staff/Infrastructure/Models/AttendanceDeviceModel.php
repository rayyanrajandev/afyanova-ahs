<?php

namespace App\Modules\Staff\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AttendanceDeviceModel extends Model
{
    use HasUuids;

    protected $table = 'attendance_devices';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'ip',
        'port',
        'serial',
        'model',
        'location',
        'password',
        'is_active',
        'last_connected_at',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'is_active' => 'boolean',
            'last_connected_at' => 'datetime',
        ];
    }
}
