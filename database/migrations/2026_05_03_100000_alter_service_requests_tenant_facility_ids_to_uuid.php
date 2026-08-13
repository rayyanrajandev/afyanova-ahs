<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_requests')) {
            return;
        }

        if (! $this->scopeColumnsRequireUuidConversion('tenant_id')
            && ! $this->scopeColumnsRequireUuidConversion('facility_id')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->convertPostgresScopeColumnsToUuid();

            return;
        }

        Schema::table('service_requests', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->change();
            $table->uuid('facility_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Column type coercion from UUID identifiers back to BIGINT is ambiguous when fresh installs already use UUID columns.
        // Use a database backup if you must roll forward from a legacy BIGINT schema.
    }

    private function scopeColumnsRequireUuidConversion(string $column): bool
    {
        $type = strtolower((string) Schema::getColumnType('service_requests', $column));

        return in_array($type, ['bigint', 'int8', 'integer', 'int'], true);
    }

    private function convertPostgresScopeColumnsToUuid(): void
    {
        DB::statement('ALTER TABLE service_requests DROP CONSTRAINT IF EXISTS service_requests_tenant_id_foreign');
        DB::statement('ALTER TABLE service_requests DROP CONSTRAINT IF EXISTS service_requests_facility_id_foreign');

        if ($this->scopeColumnsRequireUuidConversion('tenant_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE service_requests
                ALTER COLUMN tenant_id TYPE uuid USING (
                    CASE
                        WHEN tenant_id IS NULL THEN NULL
                        WHEN tenant_id::text ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                            THEN tenant_id::text::uuid
                        ELSE NULL
                    END
                )
            SQL);
        }

        if ($this->scopeColumnsRequireUuidConversion('facility_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE service_requests
                ALTER COLUMN facility_id TYPE uuid USING (
                    CASE
                        WHEN facility_id IS NULL THEN NULL
                        WHEN facility_id::text ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                            THEN facility_id::text::uuid
                        ELSE NULL
                    END
                )
            SQL);
        }

        Schema::table('service_requests', function (Blueprint $table): void {
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();
        });
    }
};
