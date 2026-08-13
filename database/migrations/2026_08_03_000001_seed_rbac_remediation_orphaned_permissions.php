<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC Security Audit — Fix 7: Seed permissions that are checked in routes
 * (can: middleware) but were never inserted into the permissions table.
 *
 * The PermissionUsageAuditor tripwire (RbacPermissionUsageAuditTest) found 10
 * orphaned permission checks — permissions referenced by live routes but not
 * granted to any role because the permission row did not exist. roles:sync
 * only looks up existing permission ids; it does not create missing ones.
 *
 * This migration creates the permission rows. config/roles.php was updated in
 * the same change to grant the billing and medical-records permissions to the
 * correct roles. The inventory permissions were already listed in
 * config/roles.php but their rows were missing from the permissions table.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const NEW_PERMISSIONS = [
        // Billing & Finance — created in Fix 1 for routes in Section 3.1
        'billing.invoices.adjust',
        'billing.invoices.update-status',
        'billing.write-offs.approve',

        // Medical Records & Encounters — created in Fix 1 for routes in Section 3.1
        'medical.records.update-status',
        'medical.records.handoff.accept',
        'medical.records.handoff.cancel',

        // Inventory department-scoped — already in config/roles.php but rows
        // were never inserted into the permissions table
        'inventory.view-own-items',
        'inventory.view-requisition-own',
        'inventory.create-requisition-own-department',
        'inventory.approve-requisition-own-department',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach (self::NEW_PERMISSIONS as $name) {
            $exists = DB::table('permissions')->where('name', $name)->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'name' => $name,
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

        DB::table('permissions')->whereIn('name', self::NEW_PERMISSIONS)->delete();
    }
};