<?php

/**
 * The contract between config/revenue.php and the seeded catalogue.
 *
 * Every other Revenue test fabricates the item it charges against —
 * RevenueTestSupport::pricedItem('CONSULT-TEST'), 'CONSULT-SEQ', and a handful
 * of random codes. That proves the machinery works. It cannot prove the
 * machinery is plugged in, because no test ever asks whether the code the
 * application actually reads at runtime resolves against the catalogue the
 * application actually ships.
 *
 * It did not. Verified on 2026-08-19: config declared
 * prepaid_required_for.consultation = true, the catalogue held 237 priced items
 * and not one consultation, so ConsultationChargeRaiser logged a warning nobody
 * read, ServiceAuthorization::notCharged() returned authorized (by deliberate
 * design), and every patient walked past the cashier. 25 Revenue tests were
 * green throughout.
 *
 * The cause was subtle enough to be worth recording: migration
 * 2026_08_19_000006 does seed the two consultation items, but resolves which
 * (tenant, facility) scopes to seed by querying chargeable_items — and on a
 * fresh database it runs in the same batch that creates that table, before any
 * seeder has filled it. The loop found nothing and seeded nothing, silently.
 * A data migration depending on data that seeders produce cannot work on a
 * fresh install; DskChargeableItemsSeeder now covers that path.
 *
 * This test exists so that a green suite means a working gate.
 * See reports/workspace-maturity/01-revenue-cashier.md, goal G1.
 */

use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Revenue\Infrastructure\Models\PriceBookEntryModel;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DskChargeableItemsSeeder;

/** Prepaid kind => the chargeable_items.catalog_type that satisfies it. */
const PREPAID_KIND_CATALOG_TYPES = [
    'consultation' => 'consultation',
    'laboratory_order' => 'lab_test',
    'radiology_order' => 'radiology_procedure',
    'clinical_procedure_order' => 'clinical_procedure',
    'pharmacy_order' => 'formulary_item',
];

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('resolves every configured consultation item code against the seeded catalogue', function (): void {
    $codes = array_unique(array_filter(array_merge(
        [(string) config('revenue.consultation.default_item_code')],
        array_values((array) config('revenue.consultation.item_codes_by_tier')),
    )));

    expect($codes)->not->toBeEmpty();

    foreach ($codes as $code) {
        $item = ChargeableItemModel::query()
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])
            ->where('status', 'active')
            ->first();

        $this->assertNotNull(
            $item,
            "config/revenue.php expects consultation item '{$code}', but no active chargeable_item carries that code. "
            .'ConsultationChargeRaiser will leave every visit uncharged and the prepaid gate will open for everyone.',
        );

        $this->assertTrue(
            PriceBookEntryModel::query()
                ->where('chargeable_item_id', $item->id)
                ->where('status', 'active')
                ->exists(),
            "Consultation item '{$code}' exists but has no active price book entry, so its charge would be raised unpriced "
            .'and a cashier could not settle it.',
        );
    }
});

it('can satisfy every prepaid gate that configuration declares enabled', function (): void {
    $enabled = array_keys(array_filter((array) config('revenue.prepaid_required_for')));

    expect($enabled)->not->toBeEmpty();

    foreach ($enabled as $kind) {
        $catalogType = PREPAID_KIND_CATALOG_TYPES[$kind] ?? null;

        $this->assertNotNull(
            $catalogType,
            "config declares prepaid gate '{$kind}', which this test does not know how to satisfy. "
            .'Add it to PREPAID_KIND_CATALOG_TYPES so the gate cannot be enabled without a catalogue behind it.',
        );

        $priced = ChargeableItemModel::query()
            ->where('catalog_type', $catalogType)
            ->where('status', 'active')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('price_book_entries')
                ->whereColumn('price_book_entries.chargeable_item_id', 'chargeable_items.id')
                ->where('price_book_entries.status', 'active'))
            ->exists();

        $this->assertTrue(
            $priced,
            "Prepaid gate '{$kind}' is enabled, but the seeded catalogue holds no active, priced "
            ."'{$catalogType}' item. Nothing can be charged for, so nothing can be paid for.",
        );
    }
});

it('leaves no chargeable item without a price a cashier can settle', function (): void {
    $unpriced = ChargeableItemModel::query()
        ->where('status', 'active')
        ->whereNotExists(fn ($q) => $q->selectRaw('1')
            ->from('price_book_entries')
            ->whereColumn('price_book_entries.chargeable_item_id', 'chargeable_items.id')
            ->where('price_book_entries.status', 'active'))
        ->pluck('code')
        ->all();

    $this->assertSame(
        [],
        $unpriced,
        'These active chargeable items have no active price. A charge raised against one is unpayable at the counter, '
        .'which strands the patient: '.implode(', ', $unpriced),
    );
});

it('can be re-seeded without duplicating an item or a price', function (): void {
    // Asked directly before arming the gate on a live database: running the
    // seeder a second time — or over a partially-seeded run — must not create
    // a second consultation item or a competing tariff. Two active prices for
    // one item would make "what does a consultation cost" ambiguous at the
    // counter.
    $itemsBefore = ChargeableItemModel::query()->count();
    $pricesBefore = PriceBookEntryModel::query()->count();

    $this->seed(DskChargeableItemsSeeder::class);
    $this->seed(DskChargeableItemsSeeder::class);

    expect(ChargeableItemModel::query()->count())->toBe($itemsBefore)
        ->and(PriceBookEntryModel::query()->count())->toBe($pricesBefore);

    foreach (['CONSULT-GENERAL-OPD', 'CONSULT-SPECIALIST-OPD'] as $code) {
        $items = ChargeableItemModel::query()->whereRaw('UPPER(code) = ?', [$code])->get();

        expect($items)->toHaveCount(1);

        expect(PriceBookEntryModel::query()
            ->where('chargeable_item_id', $items->first()->id)
            ->where('status', 'active')
            ->count())->toBe(1);
    }
});

it('scopes the consultation tariff to a facility, not globally', function (): void {
    // A globally scoped item would resolve for every facility, including ones
    // that price consultations differently — which is what the price book
    // exists to prevent.
    $item = ChargeableItemModel::query()
        ->whereRaw('UPPER(code) = ?', ['CONSULT-GENERAL-OPD'])
        ->firstOrFail();

    expect($item->facility_id)->not->toBeNull()
        ->and($item->tenant_id)->not->toBeNull();

    $price = PriceBookEntryModel::query()
        ->where('chargeable_item_id', $item->id)
        ->where('status', 'active')
        ->firstOrFail();

    expect($price->facility_id)->toBe($item->facility_id)
        ->and($price->currency_code)->toBe('TZS')
        // Null payer contract is the cash tariff — the fallback every future
        // payer tariff sits above.
        ->and($price->payer_contract_id)->toBeNull();
});
