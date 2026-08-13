<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC_Remediation_Plan.md Phase 6: findings from the first real run of the
 * new permission-usage tripwire (App\Support\Rbac\PermissionUsageAuditor).
 *
 * Group 1 — completes a rename that 2026_07_16_000001_standardize_permission_names
 * intended but that never actually took effect on this database: that
 * migration's up() only UPDATEs rows matching the old hyphenated name, but
 * those rows did not exist yet when it ran (they were created five days
 * later by something re-inserting the pre-standardization name), so the
 * rename silently affected zero rows. Renames the vestigial hyphenated rows
 * to the standardized dot-namespaced names the routes actually check.
 *
 * Group 2 — permission names checked in routes/api.php or a controller but
 * that never existed as permission rows at all, so no role could ever be
 * granted them (config/roles.php was updated in the same change to grant
 * these to the correct roles, but roles:sync only looks up existing
 * permission ids — same underlying gap as the billing insurance and
 * payments-read permission fix in 2026_07_23_000003).
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const RENAMES = [
        'laboratory-orders.view-audit-logs' => 'laboratory.orders.audit-logs.view',
        'pharmacy-orders.view-audit-logs' => 'pharmacy.orders.audit-logs.view',
        'medical-records.view-audit-logs' => 'medical.records.audit-logs.view',
        'billing-invoices.view-audit-logs' => 'billing.invoices.audit-logs.view',
    ];

    /**
     * @var array<int, string>
     */
    private const NEW_PERMISSIONS = [
        'staff.attendance.read',
        'staff.attendance.update',
        'platform.clinical-catalog.manage-clinical-procedures',
        'inventory.procurement.correct-movement',
        'inventory.procurement.manage-item-units',
        'inventory.procurement.manage-unit-prices',
        'inventory.procurement.set-opening-stock',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach (self::RENAMES as $old => $new) {
            $oldRow = DB::table('permissions')->where('name', $old)->first();
            $newExists = DB::table('permissions')->where('name', $new)->exists();

            if ($oldRow !== null && ! $newExists) {
                DB::table('permissions')->where('id', $oldRow->id)->update([
                    'name' => $new,
                    'updated_at' => $now,
                ]);
            } elseif (! $newExists) {
                DB::table('permissions')->insert([
                    'name' => $new,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            // If both rows already exist (old and new), leave as-is — a
            // manual reconciliation is safer than an automated merge of two
            // populated permission_role sets.
        }

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

        foreach (self::RENAMES as $old => $new) {
            DB::table('permissions')->where('name', $new)->update(['name' => $old]);
        }
    }
};
