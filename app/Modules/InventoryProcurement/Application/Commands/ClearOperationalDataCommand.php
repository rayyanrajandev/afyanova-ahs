<?php

namespace App\Modules\InventoryProcurement\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ClearOperationalDataCommand extends Command
{
    protected $signature = 'platform:clear-operational-data
        {--dry-run : Report what would be removed without changing the database}
        {--json : Output machine-readable JSON summary}';

    protected $description = 'Clear transactional, demo, and seeded operational data while preserving essential platform access and facility setup.';

    /**
     * @var array<int, string>
     */
    private const PRESERVED_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'password_reset_tokens',
        'sessions',
        'users',
        'roles',
        'permissions',
        'permission_role',
        'permission_user',
        'role_user',
        'tenants',
        'facilities',
        'facility_user',
        'feature_flag_overrides',
        'system_settings',
        'departments',
        'clinical_specialties',
        'clinical_privilege_catalogs',
        'staff_profiles',
        'staff_profile_specialty',
        'staff_regulatory_profiles',
        'staff_professional_registrations',
        'staff_documents',
        'staff_privilege_grants',
        'facility_resources',
    ];

    public function handle(): int
    {
        $tables = collect(Schema::getTableListing())
            ->map(function (string $table): array {
                return [
                    'qualified' => strtolower($table),
                    'base' => $this->baseTableName($table),
                ];
            })
            ->values();

        $preservedTables = $tables
            ->filter(static fn (array $table): bool => in_array($table['base'], self::PRESERVED_TABLES, true))
            ->map(static fn (array $table): string => $table['base'])
            ->values();

        $purgeTables = $tables
            ->reject(static fn (array $table): bool => in_array($table['base'], self::PRESERVED_TABLES, true))
            ->map(static fn (array $table): string => $table['qualified'])
            ->values();

        $nonEmptyTables = $this->tableCounts($purgeTables)
            ->filter(static fn (int $count): bool => $count > 0)
            ->sortDesc();

        $summary = [
            'driver' => DB::getDriverName(),
            'database' => (string) config('database.connections.'.config('database.default').'.database'),
            'dryRun' => (bool) $this->option('dry-run'),
            'preservedTableCount' => $preservedTables->count(),
            'purgeTableCount' => $purgeTables->count(),
            'nonEmptyPurgeTableCount' => $nonEmptyTables->count(),
            'rowsToRemove' => (int) $nonEmptyTables->sum(),
            'preservedTables' => $preservedTables->all(),
            'purgeTables' => $purgeTables->all(),
            'nonEmptyPurgeTables' => $nonEmptyTables
                ->map(static fn (int $count, string $table): array => [
                    'table' => self::displayTableName($table),
                    'count' => $count,
                ])
                ->values()
                ->all(),
        ];

        if (! $this->option('dry-run')) {
            $this->purgeTables($purgeTables);
            $summary['rowsRemoved'] = $summary['rowsToRemove'];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s operational data cleanup for database [%s] using driver [%s].',
            $this->option('dry-run') ? 'Dry run completed' : 'Completed',
            $summary['database'],
            $summary['driver'],
        ));

        $this->line(sprintf(
            'Preserved %d table(s); targeted %d table(s); %d non-empty purge table(s); %d row(s) %s.',
            $summary['preservedTableCount'],
            $summary['purgeTableCount'],
            $summary['nonEmptyPurgeTableCount'],
            $summary['rowsToRemove'],
            $this->option('dry-run') ? 'would be removed' : 'removed',
        ));

        if ($nonEmptyTables->isNotEmpty()) {
            $this->table(
                ['Table', 'Rows'],
                $nonEmptyTables
                    ->map(static fn (int $count, string $table): array => [self::displayTableName($table), number_format($count)])
                    ->values()
                    ->all(),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, string>  $tables
     * @return Collection<string, int>
     */
    private function tableCounts(Collection $tables): Collection
    {
        return $tables->mapWithKeys(function (string $table): array {
            return [$table => (int) DB::table($table)->count()];
        });
    }

    /**
     * @param  Collection<int, string>  $tables
     */
    private function purgeTables(Collection $tables): void
    {
        if ($tables->isEmpty()) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            foreach ($tables as $table) {
                $wrappedTable = collect(explode('.', $table))
                    ->map(static fn (string $segment): string => sprintf('"%s"', str_replace('"', '""', $segment)))
                    ->implode('.');

                DB::statement(sprintf('TRUNCATE TABLE %s RESTART IDENTITY CASCADE', $wrappedTable));
            }

            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                DB::table(self::displayTableName($table))->delete();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function baseTableName(string $table): string
    {
        return strtolower(Str::afterLast($table, '.'));
    }

    private static function displayTableName(string $table): string
    {
        return strtolower(Str::afterLast($table, '.'));
    }
}
