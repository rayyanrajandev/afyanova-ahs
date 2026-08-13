<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PricingEngine_Migration_Plan.md Phase 4. New permissions for the new
 * chargeable_items/price_book_entries admin CRUD -- seeded here (not just
 * granted in config/roles.php) so RbacPermissionUsageAuditTest's tripwire
 * doesn't flag them as checked-but-never-seeded, the exact bug class that
 * test exists to catch.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const PERMISSIONS = [
        'billing.chargeable-items.read',
        'billing.chargeable-items.manage',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $existing = DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('name')
            ->all();
        $existingSet = array_flip($existing);

        $rows = [];
        foreach (self::PERMISSIONS as $name) {
            if (isset($existingSet[$name])) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('permissions')->insert($rows);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op -- same reasoning as
        // 2026_07_23_000005_seed_baseline_permission_catalog.php: these
        // permissions are checked live in routes/api.php and role grants
        // depend on the rows existing.
    }
};
