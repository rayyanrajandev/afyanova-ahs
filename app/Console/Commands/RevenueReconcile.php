<?php

namespace App\Console\Commands;

use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryEvent;
use App\Modules\Revenue\Infrastructure\Models\RevenueTelemetryEventModel;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Answers the question nobody could answer before: did we bill everyone we
 * treated, and is anyone stuck?
 *
 * Revenue's failure paths are deliberately fail-open — a billing fault must
 * never block care. The cost of that choice is that faults are invisible unless
 * something goes looking, which is why the prepaid consultation gate could sit
 * dead in every environment with a green test suite (2026-08-19 workspace
 * maturity audit, finding C2).
 *
 * Exits non-zero when anomalies are found so a scheduler or CI job can alert on
 * it without parsing output.
 */
class RevenueReconcile extends Command
{
    protected $signature = 'revenue:reconcile
        {--since= : ISO date to report from (default: today)}
        {--quiet-when-clean : Print nothing when there is nothing to report}';

    protected $description = 'Report Revenue anomalies — uncharged services, unpayable charges, and patients stuck after paying';

    public function handle(): int
    {
        $since = $this->option('since') !== null
            ? Carbon::parse((string) $this->option('since'))->startOfDay()
            : now()->startOfDay();

        $events = RevenueTelemetryEventModel::query()
            ->where('occurred_at', '>=', $since)
            ->get();

        if ($events->isEmpty()) {
            if (! $this->option('quiet-when-clean')) {
                $this->info("No revenue anomalies since {$since->toDateString()}.");
            }

            return self::SUCCESS;
        }

        $this->warn("Revenue anomalies since {$since->toDateString()}: {$events->count()}");
        $this->newLine();

        // Patient-blocking first: these are people standing at a counter right
        // now, not a figure that will be wrong at month end.
        foreach ([true, false] as $blocking) {
            $group = $events->filter(
                fn (RevenueTelemetryEventModel $e): bool => (RevenueTelemetryEvent::tryFrom((string) $e->event_type)
                    ?->blocksAPatient() ?? false) === $blocking
            );

            if ($group->isEmpty()) {
                continue;
            }

            $this->line($blocking ? '<fg=red>Patients affected now</>' : '<fg=yellow>Revenue at risk</>');

            $rows = $group
                ->groupBy(fn (RevenueTelemetryEventModel $e): string => sprintf(
                    '%s|%s|%s',
                    $e->event_type,
                    $e->reason ?? '—',
                    $e->source_kind ?? '—',
                ))
                ->map(fn ($rows, string $key): array => [
                    ...explode('|', $key),
                    $rows->count(),
                ])
                ->values()
                ->all();

            $this->table(['Event', 'Reason', 'Service', 'Count'], $rows);
        }

        $this->newLine();
        $this->line('Detail: SELECT * FROM revenue_telemetry_events WHERE occurred_at >= \''.$since->toDateTimeString().'\';');

        return self::FAILURE;
    }
}
