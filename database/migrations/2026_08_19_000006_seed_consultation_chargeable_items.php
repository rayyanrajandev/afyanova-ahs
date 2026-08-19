<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Give consultation a price.
 *
 * The catalogue holds 237 priced items — lab tests, imaging, procedures and
 * formulary lines — and not one consultation. The retired engine never needed
 * one: it priced a visit through consultation_mappings (tier + department →
 * item), a table that was empty for its entire life, which is why consultation
 * fees were never once captured.
 *
 * Prepaid consultation cannot start without something to charge for, so this
 * seeds two tiers per facility that already has a catalogue, and a default TZS
 * price for each.
 *
 * The amounts are deliberate placeholders at plausible Tanzanian OPD levels,
 * not a pricing decision: a facility sets its own tariffs through the price
 * book, and the seeded entries are ordinary price_book_entries that a later
 * one supersedes in the normal way. What matters here is that the item exists
 * and resolves; the number is expected to be changed.
 */
return new class extends Migration
{
    /**
     * @var list<array{code: string, name: string, category: string, price: string}>
     */
    private const CONSULTATION_ITEMS = [
        [
            'code' => 'CONSULT-GENERAL-OPD',
            'name' => 'General outpatient consultation',
            'category' => 'consultation',
            'price' => '15000.00',
        ],
        [
            'code' => 'CONSULT-SPECIALIST-OPD',
            'name' => 'Specialist outpatient consultation',
            'category' => 'consultation',
            'price' => '30000.00',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('chargeable_items') || ! Schema::hasTable('price_book_entries')) {
            return;
        }

        // Seed per (tenant, facility) that already keeps a catalogue. Creating
        // globally scoped rows instead would resolve for every facility
        // including ones that price consultations differently, which the
        // price book exists to prevent.
        $scopes = DB::table('chargeable_items')
            ->select('tenant_id', 'facility_id')
            ->distinct()
            ->get();

        foreach ($scopes as $scope) {
            foreach (self::CONSULTATION_ITEMS as $item) {
                $this->seedItem($scope->tenant_id, $scope->facility_id, $item);
            }
        }
    }

    /**
     * @param  array{code: string, name: string, category: string, price: string}  $item
     */
    private function seedItem(?string $tenantId, ?string $facilityId, array $item): void
    {
        $existing = DB::table('chargeable_items')
            ->where('facility_id', $facilityId)
            ->whereRaw('UPPER(code) = ?', [$item['code']])
            ->first();

        if ($existing !== null) {
            $chargeableItemId = (string) $existing->id;
        } else {
            $chargeableItemId = (string) Str::uuid();

            DB::table('chargeable_items')->insert([
                'id' => $chargeableItemId,
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'catalog_type' => 'consultation',
                'charge_model' => 'flat',
                'code' => $item['code'],
                'name' => $item['name'],
                'category' => $item['category'],
                'default_unit' => 'visit',
                'status' => 'active',
                // Consultation is not VAT-rated for these facilities; a
                // facility that must charge tax on it sets the rate on the
                // item, and ServiceChargePricer picks it up with no code change.
                'is_taxable' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $hasPrice = DB::table('price_book_entries')
            ->where('chargeable_item_id', $chargeableItemId)
            ->where('status', 'active')
            ->exists();

        if ($hasPrice) {
            return;
        }

        DB::table('price_book_entries')->insert([
            'id' => (string) Str::uuid(),
            'chargeable_item_id' => $chargeableItemId,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            // Null payer contract is the cash tariff — the only one this phase
            // resolves, and the fallback every future payer tariff sits above.
            'payer_contract_id' => null,
            'currency_code' => 'TZS',
            'unit_price' => $item['price'],
            'tax_rate_percent' => 0,
            'is_taxable' => false,
            'tariff_version' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('chargeable_items')) {
            return;
        }

        $codes = array_column(self::CONSULTATION_ITEMS, 'code');

        $ids = DB::table('chargeable_items')
            ->whereIn('code', $codes)
            ->where('catalog_type', 'consultation')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Only safe while nothing has been charged against them. A facility
        // that has taken money for consultations must keep the item the
        // receipt refers to.
        if (Schema::hasTable('service_charges')
            && DB::table('service_charges')->whereIn('chargeable_item_id', $ids)->exists()) {
            return;
        }

        DB::table('price_book_entries')->whereIn('chargeable_item_id', $ids)->delete();
        DB::table('chargeable_items')->whereIn('id', $ids)->delete();
    }
};
