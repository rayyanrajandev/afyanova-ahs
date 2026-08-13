<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC_Remediation_Plan.md Phase 5.1: billing.insurance.read, billing.insurance.manage,
 * and billing.payments.read gate ~13 live NHIF/insurance routes in
 * routes/billing-phase1.php but never existed as rows in the permissions
 * table at all — so `php artisan roles:sync` (which only looks up existing
 * permission ids, it does not create missing ones) could never wire them to
 * FINANCE.CLAIMS/FINANCE.OFFICER even after config/roles.php was updated to
 * grant them. This creates the missing permission rows only; role grants
 * are config/roles.php's job via roles:sync, not this migration's.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'billing.insurance.read',
        'billing.insurance.manage',
        'billing.payments.read',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach (self::PERMISSIONS as $permissionName) {
            $exists = DB::table('permissions')->where('name', $permissionName)->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'name' => $permissionName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->whereIn('name', self::PERMISSIONS)->delete();
    }
};
