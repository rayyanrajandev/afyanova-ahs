<?php

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inventory_MasterData_Alignment_Plan.md Phase 5. Lossless seed from the two
 * sources of truth this table replaces:
 *  - Category rows come from InventoryItemCategory::optionMetadata() directly
 *    (the enum itself), so there is no hand-copied risk of drift on this pass.
 *  - Subcategory rows are hand-transcribed from ITEM_SUBCATEGORY_OPTIONS and
 *    GENERAL_SUBCATEGORY_OPTIONS in resources/js/pages/inventory-procurement/
 *    stock-control/IndexV2.vue, since that hardcoded frontend map is the only
 *    place this data has ever lived. Every category not explicitly listed
 *    there (12 of 14) falls back to GENERAL_SUBCATEGORY_OPTIONS today --
 *    reproduced here per-category so a future admin edit to one category's
 *    subcategories doesn't accidentally affect another's.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $categoryIdByCode = [];
        foreach (InventoryItemCategory::optionMetadata() as $sortOrder => $option) {
            $id = (string) Str::uuid();
            $categoryIdByCode[$option['value']] = $id;

            DB::table('inventory_categories')->insert([
                'id' => $id,
                'code' => $option['value'],
                'label' => $option['label'],
                'form_template' => $option['template'],
                'description' => $option['description'],
                'requires_expiry_tracking' => $option['requiresExpiryTracking'],
                'requires_cold_chain' => $option['requiresColdChain'],
                'controlled_substance_eligible' => $option['controlledSubstanceEligible'],
                'supports_medicine_details' => $option['supportsMedicineDetails'],
                'supports_storage_fields' => $option['supportsStorageFields'],
                'supports_clinical_classification' => $option['supportsClinicalClassification'],
                'is_active' => true,
                'sort_order' => $sortOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $pharmaceuticalSubcategories = [
            'analgesics' => 'Analgesics', 'antibiotics' => 'Antibiotics', 'antimalarials' => 'Antimalarials',
            'cardiovascular' => 'Cardiovascular', 'endocrine' => 'Endocrine / diabetes', 'gastrointestinal' => 'Gastrointestinal',
            'maternal_health' => 'Maternal health', 'respiratory' => 'Respiratory', 'dermatology' => 'Dermatology',
            'iv_fluids' => 'IV fluids', 'vaccines' => 'Vaccines / immunization', 'controlled_medicines' => 'Controlled medicines',
        ];

        $medicalConsumableSubcategories = [
            'syringes_needles' => 'Syringes & needles', 'dressings_bandages' => 'Dressings & bandages',
            'catheters_tubes' => 'Catheters & tubes', 'gloves_ppe' => 'Gloves & PPE',
            'sterilization_consumables' => 'Sterilization consumables', 'patient_care_consumables' => 'Patient care consumables',
        ];

        $generalSubcategories = [
            'general_supplies' => 'General supplies', 'department_consumables' => 'Department consumables',
            'maintenance_supplies' => 'Maintenance supplies', 'other' => 'Other',
        ];

        foreach ($categoryIdByCode as $categoryCode => $categoryId) {
            $subcategories = match ($categoryCode) {
                'pharmaceutical' => $pharmaceuticalSubcategories,
                'medical_consumable' => $medicalConsumableSubcategories,
                default => $generalSubcategories,
            };

            $sortOrder = 0;
            foreach ($subcategories as $code => $label) {
                DB::table('inventory_subcategories')->insert([
                    'id' => (string) Str::uuid(),
                    'category_id' => $categoryId,
                    'code' => $code,
                    'label' => $label,
                    'is_active' => true,
                    'sort_order' => $sortOrder++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('inventory_subcategories')->truncate();
        DB::table('inventory_categories')->truncate();
    }
};
