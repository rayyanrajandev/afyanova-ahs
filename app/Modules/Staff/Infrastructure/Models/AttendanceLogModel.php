<?php

namespace App\Modules\Staff\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AttendanceLogModel extends Model
{
    use HasUuids;

    protected $table = 'attendance_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'device_id',
        'uid',
        'user_id',
        'device_user_name',
        'staff_id',
        'state',
        'type',
        'record_time',
        'pulled_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'uid' => 'integer',
            'state' => 'integer',
            'type' => 'integer',
            'record_time' => 'datetime',
            'pulled_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }
}
