<?php

namespace App\Modules\Staff\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Staff\Infrastructure\Models\AttendanceDeviceModel;
use App\Modules\Staff\Infrastructure\Models\AttendanceLogModel;
use App\Modules\Staff\Infrastructure\Models\DeviceUserMapping;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Modules\Staff\Infrastructure\Services\ZKService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function logs(Request $request): JsonResponse
    {
        $query = AttendanceLogModel::query();

        if ($request->filled('device_id')) {
            $query->where('device_id', $request->device_id);
        }

        if ($request->filled('staff_id')) {
            $query->where('staff_id', $request->staff_id);
        }

        $type_val = $request->input('type');
        if ($type_val !== null && $type_val !== '') {
            $query->where('type', (int) $type_val);
        }

        if ($request->filled('date_from')) {
            $query->where('record_time', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('record_time', '<=', $request->date_to.' 23:59:59');
        }

        $query->orderBy('record_time', 'desc');

        $perPage = min((int) $request->input('per_page', 50), 200);
        $logs = $query->paginate($perPage);

        $deviceIds = $logs->getCollection()->pluck('device_id')->unique()->filter();
        $staffIds = $logs->getCollection()->pluck('staff_id')->unique()->filter();

        $devices = AttendanceDeviceModel::whereIn('id', $deviceIds)->get()->keyBy('id');
        $staffProfiles = StaffProfileModel::whereIn('id', $staffIds)->get()->keyBy('id');
        $userIds = $staffProfiles->pluck('user_id')->unique()->filter();
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $transformed = $logs->getCollection()->map(function ($log) use ($devices, $staffProfiles, $users) {
            $device = $devices->get($log->device_id);
            $staff = null;
            if ($log->staff_id && $staffModel = $staffProfiles->get($log->staff_id)) {
                $user = $staffModel->user_id ? $users->get($staffModel->user_id) : null;
                $staff = [
                    'id' => $staffModel->id,
                    'user_id' => $staffModel->user_id,
                    'user_name' => $user?->name,
                    'employee_number' => $staffModel->employee_number,
                    'department' => $staffModel->department,
                    'job_title' => $staffModel->job_title,
                ];
            }

            return [
                'id' => $log->id,
                'uid' => $log->uid,
                'user_id' => $log->user_id,
                'device_user_name' => $log->device_user_name,
                'state' => $log->state,
                'type' => $log->type,
                'record_time' => $log->record_time,
                'pulled_at' => $log->pulled_at,
                'device' => $device ? [
                    'id' => $device->id,
                    'name' => $device->name,
                    'location' => $device->location,
                ] : null,
                'staff' => $staff,
            ];
        });

        $paginated = $logs->toArray();
        $paginated['data'] = $transformed;

        return response()->json($paginated);
    }

    public function devices(Request $request): JsonResponse
    {
        $query = AttendanceDeviceModel::orderBy('name');

        if (!$request->boolean('all')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get()->map(fn ($d) => $this->transformDevice($d))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ip' => 'required|string|max:45',
            'port' => 'required|integer|min:1|max:65535',
            'password' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $device = AttendanceDeviceModel::create($validated);

        return response()->json(['data' => $this->transformDevice($device)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $device = AttendanceDeviceModel::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'ip' => 'sometimes|required|string|max:45',
            'port' => 'sometimes|required|integer|min:1|max:65535',
            'password' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $device->update($validated);

        return response()->json(['data' => $this->transformDevice($device)]);
    }

    public function destroy(string $id): JsonResponse
    {
        $device = AttendanceDeviceModel::findOrFail($id);
        $device->delete();

        return response()->json(['message' => 'Device deleted.']);
    }

    public function exportCsv(Request $request): \Illuminate\Http\Response
    {
        $query = AttendanceLogModel::query();

        if ($request->filled('device_id')) {
            $query->where('device_id', $request->device_id);
        }

        if ($request->filled('staff_id')) {
            $query->where('staff_id', $request->staff_id);
        }

        $type_val = $request->input('type');
        if ($type_val !== null && $type_val !== '') {
            $query->where('type', (int) $type_val);
        }

        if ($request->filled('date_from')) {
            $query->where('record_time', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('record_time', '<=', $request->date_to.' 23:59:59');
        }

        $logs = $query->orderBy('record_time', 'desc')->get();

        $deviceIds = $logs->pluck('device_id')->unique()->filter();
        $staffIds = $logs->pluck('staff_id')->unique()->filter();

        $devices = AttendanceDeviceModel::whereIn('id', $deviceIds)->get()->keyBy('id');
        $staffProfiles = StaffProfileModel::whereIn('id', $staffIds)->get()->keyBy('id');
        $userIds = $staffProfiles->pluck('user_id')->unique()->filter();
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $csv = "\xEF\xBB\xBF"; // BOM for Excel UTF-8
        $csv .= "Employee #,Name,Type,Date/Time,Device,Department,Job Title\n";

        foreach ($logs as $log) {
            $staff = $log->staff_id ? $staffProfiles->get($log->staff_id) : null;
            $user = $staff && $staff->user_id ? $users->get($staff->user_id) : null;
            $device = $devices->get($log->device_id);

            $name = $user?->name ?? $log->device_user_name ?? "UID #{$log->user_id}";
            $empNum = $staff?->employee_number ?? '';
            $type = self::typeLabel($log->type);
            $time = $log->record_time ? $log->record_time->format('Y-m-d H:i:s') : '';
            $devName = $device?->name ?? '';
            $dept = $staff?->department ?? '';
            $jobTitle = $staff?->job_title ?? '';

            $row = [$empNum, $name, $type, $time, $devName, $dept, $jobTitle];
            $row = array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $row);
            $csv .= implode(',', $row)."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="attendance_logs.csv"',
        ]);
    }

    private static function typeLabel(?int $type): string
    {
        $labels = [0 => 'Check In', 1 => 'Check Out', 2 => 'Break Out', 3 => 'Break In', 4 => 'Overtime In', 5 => 'Overtime Out'];

        return $type !== null ? ($labels[$type] ?? "Type {$type}") : 'Unknown';
    }

    private function transformDevice($device): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'ip' => $device->ip,
            'port' => $device->port,
            'password' => $device->password,
            'serial' => $device->serial,
            'model' => $device->model,
            'location' => $device->location,
            'is_active' => $device->is_active,
            'last_connected_at' => $device->last_connected_at,
        ];
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);

        if (empty($ids) || !is_array($ids)) {
            return response()->json(['message' => 'No IDs provided'], 400);
        }

        $deleted = AttendanceLogModel::whereIn('id', $ids)->delete();

        return response()->json(['message' => "Deleted {$deleted} attendance records."]);
    }

    public function clear(): JsonResponse
    {
        $devices = AttendanceDeviceModel::where('is_active', true)->get();

        if ($devices->isEmpty()) {
            return response()->json(['message' => 'No active devices found'], 400);
        }

        $cleared = 0;
        foreach ($devices as $device) {
            $cleared += AttendanceLogModel::where('device_id', $device->id)->delete();
            DeviceUserMapping::where('device_id', $device->id)->delete();
        }

        return response()->json(['message' => "Cleared {$cleared} attendance records. Run pull to re-sync."]);
    }

    public function pull(ZKService $zkService): JsonResponse
    {
        $devices = AttendanceDeviceModel::where('is_active', true)->get();

        if ($devices->isEmpty()) {
            return response()->json(['message' => 'No active devices found'], 400);
        }

        $results = [];

        foreach ($devices as $device) {
            try {
                $connected = $zkService->connect($device);
                if (!$connected) {
                    $results[] = ['device' => $device->name, 'status' => 'connection_failed'];
                    continue;
                }

                $result = $zkService->pullAttendance();
                $zkService->disconnect();
                $results[] = $result;
            } catch (\Throwable $e) {
                $results[] = ['device' => $device->name, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return response()->json(['results' => $results]);
    }

    public function pushLogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
            'device_name' => 'nullable|string|max:255',
            'device_ip' => 'nullable|string|max:45',
            'device_serial' => 'nullable|string|max:255',
            'device_model' => 'nullable|string|max:255',
            'pulled_at' => 'required|date_format:Y-m-d H:i:s',
            'users' => 'nullable|array',
            'users.*' => 'nullable|string|max:255',
            'logs' => 'required|array',
            'logs.*.uid' => 'required|integer',
            'logs.*.user_id' => 'required|string',
            'logs.*.device_user_name' => 'nullable|string|max:255',
            'logs.*.state' => 'required|integer',
            'logs.*.type' => 'nullable|integer',
            'logs.*.record_time' => 'required|date_format:Y-m-d H:i:s',
        ]);

        $device = AttendanceDeviceModel::firstOrCreate(
            ['serial' => $validated['device_serial'] ?? $validated['device_name']],
            [
                'name' => $validated['device_name'] ?? $validated['device_id'],
                'ip' => $validated['device_ip'] ?? '0.0.0.0',
                'port' => 4370,
                'model' => $validated['device_model'] ?? null,
                'is_active' => true,
            ]
        );

        if (!empty($validated['users'])) {
            $this->syncDeviceUsers($device, $validated['users']);
        }

        $pulledAt = $validated['pulled_at'];
        $synced = 0;
        $skipped = 0;
        $lastRecordTime = null;

        foreach ($validated['logs'] as $log) {
            try {
                AttendanceLogModel::create([
                    'device_id' => $device->id,
                    'uid' => $log['uid'],
                    'user_id' => (string) $log['user_id'],
                    'device_user_name' => $log['device_user_name'] ?? null,
                    'state' => $log['state'],
                    'type' => $log['type'] ?? null,
                    'record_time' => $log['record_time'],
                    'pulled_at' => $pulledAt,
                ]);
                $synced++;
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'duplicate')) {
                    $skipped++;
                } else {
                    throw $e;
                }
            }

            if (!$lastRecordTime || $log['record_time'] > $lastRecordTime) {
                $lastRecordTime = $log['record_time'];
            }
        }

        $device->update(['last_connected_at' => now()]);

        return response()->json([
            'message' => "Synced {$synced} records, {$skipped} duplicates skipped.",
            'device_id' => $device->id,
            'synced' => $synced,
            'skipped' => $skipped,
            'last_record_time' => $lastRecordTime,
            'server_time' => now()->toDateTimeString(),
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_serial' => 'nullable|string|max:255',
            'device_name' => 'nullable|string|max:255',
            'last_sync_at' => 'nullable|date_format:Y-m-d H:i:s',
            'pending_count' => 'nullable|integer|min:0',
            'users' => 'nullable|array',
            'users.*' => 'nullable|string|max:255',
        ]);

        $device = null;
        if (!empty($validated['device_serial'])) {
            $device = AttendanceDeviceModel::firstOrCreate(
                ['serial' => $validated['device_serial']],
                [
                    'name' => $validated['device_name'] ?? $validated['device_serial'],
                    'ip' => '0.0.0.0',
                    'port' => 4370,
                    'is_active' => true,
                ]
            );

            $device->update(['last_connected_at' => now()]);

            if (!empty($validated['users'])) {
                $this->syncDeviceUsers($device, $validated['users']);
            }
        }

        return response()->json([
            'status' => 'ok',
            'server_time' => now()->toDateTimeString(),
            'device_id' => $device?->id,
        ]);
    }

    private function syncDeviceUsers($device, array $users): void
    {
        $userInserts = [];
        foreach ($users as $deviceUserId => $name) {
            if ($name) {
                $userInserts[] = [
                    'device_id' => $device->id,
                    'device_user_id' => (int) $deviceUserId,
                    'name' => $name,
                ];
            }
        }
        if ($userInserts) {
            DeviceUserMapping::upsert($userInserts, ['device_id', 'device_user_id'], ['name']);
        }

        AttendanceLogModel::where('device_id', $device->id)
            ->whereNull('device_user_name')
            ->whereIn('user_id', array_keys($users))
            ->chunkById(200, function ($logs) use ($users) {
                foreach ($logs as $log) {
                    $name = $users[$log->user_id] ?? null;
                    if ($name) {
                        $log->update(['device_user_name' => $name]);
                    }
                }
            });
    }

    public function agentStatus(): JsonResponse
    {
        $mode = config('attendance.agent_token') ? 'cloud' : 'local';

        $lastActiveDevice = AttendanceDeviceModel::whereNotNull('last_connected_at')
            ->orderBy('last_connected_at', 'desc')
            ->first();

        return response()->json([
            'mode' => $mode,
            'last_heartbeat_at' => $lastActiveDevice?->last_connected_at?->toDateTimeString(),
            'last_device_name' => $lastActiveDevice?->name,
        ]);
    }

    public function testConnection(string $id, ZKService $zkService): JsonResponse
    {
        $device = AttendanceDeviceModel::findOrFail($id);

        try {
            $connected = $zkService->connect($device);
            if (!$connected) {
                return response()->json(['message' => 'Failed to connect to device'], 400);
            }

            $info = $zkService->getDeviceInfo();
            $zkService->disconnect();

            return response()->json([
                'message' => 'Connection successful',
                'info' => $info,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Connection failed: '.$e->getMessage()], 400);
        }
    }
}
