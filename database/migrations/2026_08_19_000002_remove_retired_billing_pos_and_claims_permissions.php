<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier Workspace, Phase 2 — retire the billing, POS and claims permission
 * namespaces.
 *
 * 65 permissions, roughly a quarter of a 290-entry catalogue, guarding routes
 * that no longer exist. Leaving them behind would be worse than clutter: an
 * ability that can be granted but protects nothing reads to an administrator
 * as a capability the system has, and the RBAC audit cannot tell the
 * difference between one of these and a real permission whose route was
 * removed by mistake.
 *
 * Their 55 role grants go with them. `patients.insurance.manage` and
 * `patients.insurance.verify` are deliberately untouched — different
 * namespace, and they guard the three reception insurance routes that are
 * still live under App\Modules\Payer.
 *
 * Phase 6 seeds the replacement `cashier.*` catalogue.
 */
return new class extends Migration
{
    /**
     * Both separators are matched on purpose: the catalogue mixes
     * `billing.invoices.read` with `billing-invoices.view-audit-logs`.
     *
     * @var list<string>
     */
    private const RETIRED_PREFIXES = [
        'billing.', 'billing-',
        'pos.', 'pos-',
        'claims.', 'claims-',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $retiredIds = DB::table('permissions')
            ->where(function ($query): void {
                foreach (self::RETIRED_PREFIXES as $prefix) {
                    $query->orWhere('name', 'like', $prefix.'%');
                }
            })
            ->pluck('id');

        if ($retiredIds->isEmpty()) {
            return;
        }

        foreach (['permission_role', 'permission_user'] as $pivot) {
            if (Schema::hasTable($pivot)) {
                DB::table($pivot)->whereIn('permission_id', $retiredIds)->delete();
            }
        }

        DB::table('permissions')->whereIn('id', $retiredIds)->delete();
    }

    /**
     * Intentionally a no-op.
     *
     * The permission rows were seeded by roughly a dozen historical
     * migrations, each owning its own subset; re-inserting them here would
     * duplicate that logic and could not restore the role grants, which are
     * defined by config/roles.php rather than by any migration. Recovery is
     * `git revert` of the Phase 2 commit followed by a re-seed.
     */
    public function down(): void
    {
        // No-op. See the docblock above.
    }
};
