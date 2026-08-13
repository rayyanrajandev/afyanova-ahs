<?php

namespace App\Console\Commands;

use App\Support\Rbac\PermissionUsageAuditor;
use Illuminate\Console\Command;

/**
 * RBAC_Remediation_Plan.md Phase 6.1. Run with `php artisan rbac:audit-permissions`.
 * Exits non-zero if any permission is checked in code but never seeded (and
 * isn't a known Gate::define()-only composite ability) — the exact bug class
 * documented in RBAC_Audit_Report.md §6.1/§6.2.
 */
class AuditPermissionUsage extends Command
{
    protected $signature = 'rbac:audit-permissions {--verbose-report : Also list unused-but-seeded permissions}';

    protected $description = 'Audit permission names checked in code against permissions actually seeded in the database';

    public function handle(PermissionUsageAuditor $auditor): int
    {
        $report = $auditor->audit();

        $this->info(sprintf(
            'Scanned %d checked permission names, %d seeded permission rows, %d Gate::define()-only allowlisted abilities.',
            count($report['checked']),
            count($report['seeded']),
            count($report['gateOnlyAllowlist']),
        ));

        if ($report['orphanedChecks'] === []) {
            $this->info('No orphaned permission checks found — every permission checked in code is either seeded or an allowlisted Gate ability.');
        } else {
            $this->error(sprintf(
                '%d orphaned permission check(s) found — checked in code but never seeded and not on the Gate::define() allowlist:',
                count($report['orphanedChecks']),
            ));
            foreach ($report['orphanedChecks'] as $name) {
                $this->line("  - {$name}");
            }
            $this->line('');
            $this->line('These can only ever pass for a universal-admin-bypass user. Either seed the permission to the correct role(s), or fix the naming mismatch if this is a typo against an existing seeded permission.');
        }

        if ($this->option('verbose-report') && $report['unusedSeeded'] !== []) {
            $this->line('');
            $this->warn(sprintf('%d seeded permission(s) are never checked anywhere (informational only, not a failure):', count($report['unusedSeeded'])));
            foreach ($report['unusedSeeded'] as $name) {
                $this->line("  - {$name}");
            }
        }

        return $report['orphanedChecks'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
