<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Stool Microscopy" (LAB-STOOL-MICRO-001) was a plain flat-panel catalog
 * item that duplicated "Stool Analysis" (LAB-STOOL-001) — the newer
 * structured-template item captures everything it did, plus more (see
 * reports/lab-result-templates-2027-modernization-plan.md, Phase 6).
 * Removed from LaboratoryClinicalCatalogSeeder's blueprint list so it's
 * never (re)created; this retires any copy already seeded onto an
 * already-provisioned database (deploys only run `migrate`, not
 * `db:seed` — see docker/start.sh). Soft-retire, not delete: existing
 * orders referencing it keep their history intact, it just stops
 * appearing as an orderable item (order creation only lists
 * status = 'active' catalog rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_clinical_catalog_items')
            ->where('catalog_type', 'lab_test')
            ->where('code', 'LAB-STOOL-MICRO-001')
            ->update(['status' => 'retired']);
    }

    public function down(): void
    {
        DB::table('platform_clinical_catalog_items')
            ->where('catalog_type', 'lab_test')
            ->where('code', 'LAB-STOOL-MICRO-001')
            ->where('status', 'retired')
            ->update(['status' => 'active']);
    }
};
