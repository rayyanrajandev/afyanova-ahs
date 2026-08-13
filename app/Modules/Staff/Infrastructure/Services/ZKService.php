<?php

namespace App\Modules\Staff\Infrastructure\Services;

use App\Modules\Staff\Infrastructure\Models\AttendanceDeviceModel;
use App\Modules\Staff\Infrastructure\Models\AttendanceLogModel;
use App\Modules\Staff\Infrastructure\Models\DeviceUserMapping;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use CodingLibs\ZktecoPhp\Libs\ZKTeco;

class ZKService
{
    private ?ZKTeco $device = null;

    private ?AttendanceDeviceModel $deviceModel = null;

    public function connect(AttendanceDeviceModel $device): bool
    {
        $this->deviceModel = $device;

        $this->device = new ZKTeco(
            $device->ip,
            $device->port ?? 4370,
            false,
            config('attendance.connection_timeout', 25),
            $device->password ? (int) $device->password : 0,
        );

        if (!$this->device->connect()) {
            return false;
        }

        $device->update(['last_connected_at' => now()]);

        return true;
    }

    public function disconnect(): void
    {
        if ($this->device) {
            $this->device->disconnect();
            $this->device = null;
            $this->deviceModel = null;
        }
    }

    public function syncDeviceUsers(): int
    {
        $deviceUsers = $this->device->getUsers();
        $synced = 0;

        foreach ($deviceUsers as $deviceUser) {
            DeviceUserMapping::upsert(
                [
                    'device_id' => $this->deviceModel->id,
                    'device_user_id' => (int) $deviceUser['user_id'],
                    'name' => $deviceUser['name'],
                ],
                ['device_id', 'device_user_id'],
                ['name'],
            );
            $synced++;
        }

        return $synced;
    }

    public function pullAttendance(): array
    {
        if (!$this->device || !$this->deviceModel) {
            throw new \RuntimeException('Not connected to any device. Call connect() first.');
        }

        $this->device->disableDevice();

        $usersSynced = 0;
        try {
            $usersSynced = $this->syncDeviceUsers();
        } catch (\Throwable $e) {
            logger()->warning('ZK: Failed to sync device users', ['error' => $e->getMessage(), 'device' => $this->deviceModel->name]);
        }

        // Detect device timezone offset by comparing device time with UTC
        $tzOffsetMinutes = 0;
        try {
            $deviceTimeStr = $this->device->getTime();
            if ($deviceTimeStr) {
                $deviceTime = \Carbon\Carbon::parse($deviceTimeStr);
                $utcNow = now()->utc();
                $tzOffsetMinutes = (int) $deviceTime->diffInMinutes($utcNow, true);
                logger()->info('ZK: Device timezone offset detected', [
                    'device_time' => $deviceTimeStr,
                    'utc_now' => $utcNow->toDateTimeString(),
                    'offset_minutes' => $tzOffsetMinutes,
                    'device' => $this->deviceModel->name,
                ]);
            }
        } catch (\Throwable $e) {
            logger()->warning('ZK: Failed to detect device timezone', ['error' => $e->getMessage(), 'device' => $this->deviceModel->name]);
        }

        $logs = $this->device->getAttendances();

        $this->device->enableDevice();

        $pulledAt = now();
        $synced = 0;
        $skipped = 0;

        foreach ($logs as $log) {
            $deviceUserId = (int) $log['user_id'];
            $staff = StaffProfileModel::where('employee_number', (string) $log['user_id'])->first();

            $deviceUserName = null;
            $recordTime = $log['record_time'];

            // Adjust device local time to UTC (offset will be positive when device is ahead of UTC)
            if ($tzOffsetMinutes !== 0 && $recordTime) {
                $recordTime = \Carbon\Carbon::parse($recordTime)->subMinutes(abs($tzOffsetMinutes))->toDateTimeString();
            }

            if (!$staff) {
                $mapping = DeviceUserMapping::where('device_id', $this->deviceModel->id)
                    ->where('device_user_id', $deviceUserId)
                    ->first();
                if ($mapping) {
                    $deviceUserName = $mapping->name;
                    if ($mapping->staff_id) {
                        $staff = StaffProfileModel::find($mapping->staff_id);
                    }
                }
            }

            try {
                AttendanceLogModel::create([
                    'device_id' => $this->deviceModel->id,
                    'uid' => $log['uid'],
                    'user_id' => (string) $log['user_id'],
                    'device_user_name' => $deviceUserName,
                    'staff_id' => $staff?->id,
                    'state' => $log['state'],
                    'type' => $log['type'] ?? null,
                    'record_time' => $recordTime,
                    'pulled_at' => $pulledAt,
                    'raw_data' => $log,
                ]);
                $synced++;
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'duplicate')) {
                    $skipped++;
                } else {
                    throw $e;
                }
            }
        }

        // Backfill device_user_name on existing records
        AttendanceLogModel::where('device_id', $this->deviceModel->id)
            ->whereNull('device_user_name')
            ->whereNotNull('user_id')
            ->chunkById(100, function ($existingLogs) {
                foreach ($existingLogs as $existingLog) {
                    $mapping = DeviceUserMapping::where('device_id', $this->deviceModel->id)
                        ->where('device_user_id', (int) $existingLog->user_id)
                        ->first();
                    if ($mapping?->name) {
                        $existingLog->update(['device_user_name' => $mapping->name]);
                    }
                }
            });

        return [
            'device' => $this->deviceModel->name,
            'total_on_device' => count($logs),
            'synced' => $synced,
            'skipped_duplicates' => $skipped,
            'device_users_synced' => $usersSynced,
        ];
    }

    public function getDeviceInfo(): array
    {
        if (!$this->device) {
            return [];
        }

        return [
            'name' => $this->device->deviceName(),
            'serial' => $this->device->serialNumber(),
            'vendor' => $this->device->vendorName(),
            'version' => $this->device->version(),
            'platform' => $this->device->platform(),
        ];
    }
}
