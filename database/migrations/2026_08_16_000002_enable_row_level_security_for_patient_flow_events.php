<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the tenant-isolation RLS policy set (2026_08_04_000001) to cover
 * patient_flow_events. A separate migration rather than an edit to that one:
 * it has already run in every environment, so re-listing the table there
 * would never take effect.
 *
 * Policy shape is copied verbatim from that migration — same USING/WITH CHECK
 * predicates, same super-admin bypass, same pgsql-only guard (the test suite
 * runs SQLite, where RLS does not exist).
 */
return new class extends Migration
{
    private const TABLE = 'patient_flow_events';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'tenant_id')) {
            return;
        }

        $table = self::TABLE;
        $policyName = "tenant_isolation_policy_{$table}";
        $bypassPolicyName = "tenant_isolation_bypass_{$table}";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;");

        DB::statement("DROP POLICY IF EXISTS {$policyName} ON {$table};");
        DB::statement("DROP POLICY IF EXISTS {$bypassPolicyName} ON {$table};");

        DB::statement("
            CREATE POLICY {$policyName} ON {$table}
                FOR ALL
                USING (tenant_id::text = current_setting('app.tenant_id')::text)
                WITH CHECK (tenant_id::text = current_setting('app.tenant_id')::text);
        ");

        DB::statement("
            CREATE POLICY {$bypassPolicyName} ON {$table}
                FOR ALL
                USING (current_setting('app.bypass_tenant_isolation') = 'true')
                WITH CHECK (current_setting('app.bypass_tenant_isolation') = 'true');
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $table = self::TABLE;

        DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy_{$table} ON {$table};");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_bypass_{$table} ON {$table};");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;");
    }
};
