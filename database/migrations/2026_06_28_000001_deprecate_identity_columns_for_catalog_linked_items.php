<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Inventory Items ──────────────────────────────────────────────
        // Make identity columns nullable so catalog-linked items don't need to store duplicates.
        // Non-catalog items still require these via form validation.
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->string('item_name', 180)->nullable()->change();
            $table->string('unit', 40)->nullable()->change();
        });

        // Backfill existing catalog-linked inventory items from their clinical catalog source
        $linkedItems = DB::table('inventory_items')
            ->join('platform_clinical_catalog_items', 'inventory_items.clinical_catalog_item_id', '=', 'platform_clinical_catalog_items.id')
            ->select(
                'inventory_items.id',
                'platform_clinical_catalog_items.name AS catalog_name',
                'platform_clinical_catalog_items.unit AS catalog_unit',
                'platform_clinical_catalog_items.metadata AS catalog_metadata',
                'platform_clinical_catalog_items.codes AS catalog_codes',
            )
            ->whereNotNull('inventory_items.clinical_catalog_item_id')
            ->get();

        foreach ($linkedItems as $item) {
            $metadata = is_array($item->catalog_metadata) ? $item->catalog_metadata : [];
            $updates = [];

            // Refresh identity fields from catalog
            if ($item->catalog_name !== null) {
                $updates['item_name'] = $item->catalog_name;
            }

            $stockUnit = $metadata['stockUnit'] ?? $metadata['stock_unit'] ?? $item->catalog_unit;
            if ($stockUnit !== null) {
                $updates['unit'] = $stockUnit;
            }

            $updates['generic_name'] = $metadata['genericName'] ?? $metadata['generic_name'] ?? null;
            $updates['dosage_form'] = $metadata['dosageForm'] ?? $metadata['dosage_form'] ?? null;
            $updates['strength'] = $metadata['strength'] ?? null;
            $updates['dispensing_unit'] = $metadata['dispensingUnit'] ?? $metadata['dispensing_unit'] ?? $item->catalog_unit ?? null;
            $updates['subcategory'] = DB::table('platform_clinical_catalog_items')
                ->where('id', $item->id)
                ->value('category');

            if (is_array($item->catalog_codes) && $item->catalog_codes !== []) {
                $updates['codes'] = json_encode($item->catalog_codes);
            }

            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('inventory_items')->where('id', $item->id)->update($updates);
            }
        }

        // ── Billing Service Catalog Items ────────────────────────────────
        // service_code has a unique constraint — keep it required.
        // service_name can be nullable (read from catalog for linked items).
        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            $table->string('service_name', 255)->nullable()->change();
        });

        // Backfill existing catalog-linked billing items from their clinical catalog source
        $linkedBillingItems = DB::table('billing_service_catalog_items')
            ->join('platform_clinical_catalog_items', 'billing_service_catalog_items.clinical_catalog_item_id', '=', 'platform_clinical_catalog_items.id')
            ->select(
                'billing_service_catalog_items.id',
                'platform_clinical_catalog_items.name AS catalog_name',
                'platform_clinical_catalog_items.unit AS catalog_unit',
                'platform_clinical_catalog_items.department_id AS catalog_department_id',
                'platform_clinical_catalog_items.facility_tier AS catalog_facility_tier',
                'platform_clinical_catalog_items.description AS catalog_description',
            )
            ->whereNotNull('billing_service_catalog_items.clinical_catalog_item_id')
            ->get();

        foreach ($linkedBillingItems as $item) {
            $updates = [];

            if ($item->catalog_name !== null) {
                $updates['service_name'] = $item->catalog_name;
            }

            if ($item->catalog_unit !== null && ($item->catalog_unit ?? '') !== '') {
                $updates['unit'] = $item->catalog_unit;
            }

            if ($item->catalog_department_id !== null) {
                $updates['department_id'] = $item->catalog_department_id;
            }

            if ($item->catalog_facility_tier !== null) {
                $updates['facility_tier'] = $item->catalog_facility_tier;
            }

            if ($item->catalog_description !== null) {
                $updates['description'] = $item->catalog_description;
            }

            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('billing_service_catalog_items')->where('id', $item->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->string('item_name', 180)->nullable(false)->change();
            $table->string('unit', 40)->nullable(false)->change();
        });

        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            $table->string('service_name', 255)->nullable(false)->change();
        });
    }
};
