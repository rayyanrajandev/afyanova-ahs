<?php

namespace App\Console\Commands;

use App\Modules\Staff\Infrastructure\Models\AttendanceDeviceModel;
use App\Modules\Staff\Infrastructure\Services\ZKService;
use Illuminate\Console\Command;

class PullAttendanceCommand extends Command
{
    protected $signature = 'attendance:pull
        {--device= : Pull from a specific device ID}
        {--once : Pull once and exit (default, useful for testing)}';

    protected $description = 'Pull attendance logs from ZKTeco devices';

    public function handle(ZKService $zkService): int
    {
        $deviceId = $this->option('device');

        $query = AttendanceDeviceModel::where('is_active', true);
        if ($deviceId) {
            $query->where('id', $deviceId);
        }

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->warn('No active attendance devices found.');

            return Command::SUCCESS;
        }

        $results = [];

        foreach ($devices as $device) {
            $this->info("Connecting to {$device->name} ({$device->ip}:{$device->port})...");

            try {
                $connected = $zkService->connect($device);
                if (!$connected) {
                    $this->error("  Failed to connect to {$device->name}");

                    continue;
                }

                $this->line('  Connected. Pulling attendance...');

                $result = $zkService->pullAttendance();

                $zkService->disconnect();

                $this->line("  Done: {$result['synced']} synced, {$result['skipped_duplicates']} duplicates skipped (of {$result['total_on_device']} on device)");

                $results[] = $result;
            } catch (\Throwable $e) {
                $this->error("  Error: {$e->getMessage()}");
            }
        }

        $totalSynced = array_sum(array_column($results, 'synced'));
        $totalSkipped = array_sum(array_column($results, 'skipped_duplicates'));

        $this->newLine();
        $this->info("Finished. Total synced: {$totalSynced}, duplicates skipped: {$totalSkipped}");

        return Command::SUCCESS;
    }
}
