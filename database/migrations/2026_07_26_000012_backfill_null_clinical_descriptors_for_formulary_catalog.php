<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1.5 clinical-descriptor backfill.  The original Phase 1 backfill
 * (2026_07_26_000002) only copied values from linked inventory_items and
 * skipped catalogue rows with no inventory link, leaving the typed columns
 * null.  This backfill derives the missing values from the existing
 * name/unit/category fields for every formulary_item row that still has a
 * null generic_name, using the same derivation rules as
 * DskFormularyClinicalCatalogSeeder.
 *
 * Safe to run multiple times (WHERE … IS NULL guards).
 */
return new class extends Migration
{
    /** Codes of controlled substances. */
    private array $controlledCodes = [
        'MED-TRAM-50CAP', 'MED-TRAM-2IV',
        'MED-DIAZ-5TAB', 'MED-DIAZ-10IV',
        'MED-PREG-75CAP',
    ];

    /** Codes of cold-chain items. */
    private array $coldChainCodes = [
        'MED-TETAN-05IM',
    ];

    /** Overrides keyed by item code. */
    private array $overrides = [];

    public function up(): void
    {
        $this->buildOverrides();

        $validDosageForms = [
            'tablet', 'capsule', 'dispersible tablet', 'chewable tablet', 'effervescent tablet',
            'powder', 'syrup', 'suspension', 'solution', 'elixir', 'mixture',
            'injection', 'cream', 'ointment', 'gel', 'lotion',
            'eye drops', 'ear drops', 'nasal drops',
            'suppository', 'pessary',
            'inhaler', 'spray', 'patch',
        ];

        $catalogItems = DB::table('platform_clinical_catalog_items')
            ->where('catalog_type', 'formulary_item')
            ->where(function ($q) use ($validDosageForms) {
                $q->whereNull('generic_name')
                  ->orWhereNotIn('dosage_form', $validDosageForms);
            })
            ->select(['id', 'code', 'name', 'unit', 'category'])
            ->orderBy('id')
            ->get();

        if ($catalogItems->isEmpty()) {
            return;
        }

        $updatedCount = 0;

        foreach ($catalogItems as $item) {
            $derived = $this->deriveFields($item);
            if ($derived === null) {
                continue;
            }

            DB::table('platform_clinical_catalog_items')
                ->where('id', $item->id)
                ->update($derived);

            $updatedCount++;
        }

        // Intentionally no post-backfill notification — this is a pure data backfill.
    }

    public function down(): void
    {
        // No-op: reversing would discard derived clinical data.
    }

    private function buildOverrides(): void
    {
        $this->overrides = [
            'MED-CODRIL-100SYR' => ['generic_name' => 'Codeine-based cough preparation', 'strength' => null],
            'MED-COUGH-100SYR' => ['generic_name' => 'Cough suppressant combination', 'strength' => null],
            'MED-DRCOLD-100SYR' => ['generic_name' => 'Phenylephrine + Chlorphenamine', 'strength' => null],
            'MED-ZECUF-100SYR' => ['generic_name' => 'Herbal cough preparation', 'strength' => null],
            'MED-BBE-100LOT' => ['generic_name' => 'Moisturising lotion', 'strength' => null],
            'MED-BURN-30CREAM' => ['generic_name' => 'Burn care cream', 'strength' => null],
            'MED-BELLAD-100SYR' => ['generic_name' => 'Belladonna tincture', 'strength' => null],
            'MED-GRIPE-100SYR' => ['generic_name' => 'Gripe water', 'strength' => null],
            'MED-MULTV-TAB' => ['generic_name' => 'Multivitamin', 'strength' => null],
            'MED-MULTV-100SYR' => ['generic_name' => 'Multivitamin', 'strength' => null],
            'MED-VITBC-10TAB' => ['generic_name' => 'Vitamin B complex', 'strength' => null],
            'MED-VITBC-100SYR' => ['generic_name' => 'Vitamin B complex', 'strength' => null],
            'MED-VITB-10IM' => ['generic_name' => 'Vitamin B complex', 'strength' => null],
            'MED-SKDERM-30CREAM' => ['generic_name' => 'Clotrimazole + Betamethasone', 'strength' => null],
            'MED-GENTR-10CREAM' => ['generic_name' => 'Betamethasone + Gentamicin', 'strength' => null],
            'MED-GYNEX-PESS' => ['generic_name' => 'Miconazole + Metronidazole', 'strength' => null],
            'MED-METMI-200TAB' => ['generic_name' => 'Metronidazole + Miconazole', 'strength' => null],
            'MED-DUOCO-360TAB' => ['generic_name' => 'Ibuprofen + Paracetamol', 'strength' => null],
            'MED-TERMID-100SYR' => ['generic_name' => 'Ibuprofen + Paracetamol', 'strength' => null],
            'MED-CALZ-100LOT' => ['generic_name' => 'Calamine + Zinc oxide', 'strength' => null],
            'MED-ALUME-12TAB' => ['generic_name' => 'Artemether + Lumefantrine', 'strength' => '20/120 mg'],
            'MED-ALUME-24TAB' => ['generic_name' => 'Artemether + Lumefantrine', 'strength' => '20/120 mg'],
            'MED-ALUME-6TAB' => ['generic_name' => 'Artemether + Lumefantrine', 'strength' => '80/480 mg'],
            'MED-LONART-24SYR' => ['generic_name' => 'Artemether + Lumefantrine', 'strength' => '80/480 mg per 24 ml'],
            'MED-AL-22SYR' => ['generic_name' => 'Artemether + Lumefantrine', 'strength' => '22.4 mg/ml'],
            'MED-ARTE-80IM' => ['generic_name' => 'Artemether', 'strength' => '80 mg/ml'],
            'MED-ARTSN-60IV' => ['generic_name' => 'Artesunate', 'strength' => '60 mg'],
            'MED-ARTSN-120IV' => ['generic_name' => 'Artesunate', 'strength' => '120 mg'],
            'MED-MALAF-525TAB' => ['generic_name' => 'Sulfamethoxypyrazine + Pyrimethamine', 'strength' => '525 mg'],
            'MED-SILVEX-10CREAM' => ['generic_name' => 'Silver sulfadiazine + Chlorhexidine', 'strength' => null],
            'MED-AMPCLOX-500CAP' => ['generic_name' => 'Ampicillin + Cloxacillin', 'strength' => '500 mg'],
            'MED-AMPCLX-100SYR' => ['generic_name' => 'Ampicillin + Cloxacillin', 'strength' => null],
            'MED-AMPCLXN-06SYR' => ['generic_name' => 'Ampicillin + Cloxacillin', 'strength' => '60 mg/ml'],
            'MED-AMPCLOX-500IV' => ['generic_name' => 'Ampicillin + Cloxacillin', 'strength' => '500 mg'],
            'MED-AMOCL-625TAB' => ['generic_name' => 'Amoxicillin + Clavulanate', 'strength' => '625 mg'],
            'MED-AMOCL-375TAB' => ['generic_name' => 'Amoxicillin + Clavulanate', 'strength' => '375 mg'],
            'MED-AMOCL-100SYR' => ['generic_name' => 'Amoxicillin + Clavulanate', 'strength' => null],
            'MED-AMOCL-12IV' => ['generic_name' => 'Amoxicillin + Clavulanate', 'strength' => '1.2 g'],
            'MED-CEFTRS-15IV' => ['generic_name' => 'Ceftriaxone + Sulbactam', 'strength' => '1.5 g'],
            'MED-CIPT-600TAB' => ['generic_name' => 'Ciprofloxacin + Tinidazole', 'strength' => '600 mg'],
            'MED-METGLIM-501TAB' => ['generic_name' => 'Metformin + Glimepiride', 'strength' => '500 mg/1 mg'],
            'MED-NEURO-300TAB' => ['generic_name' => 'Methylcobalamin', 'strength' => '300 mcg'],
            'MED-PENAD-24IM' => ['generic_name' => 'Benzathine benzylpenicillin', 'strength' => '2.4 MU'],
            'MED-BENZP-5MU' => ['generic_name' => 'Benzylpenicillin', 'strength' => '5 MU'],
            'MED-ANTAC-100SYR' => ['generic_name' => 'Aluminium + Magnesium hydroxide', 'strength' => null],
            'MED-CMAG-250TAB' => ['generic_name' => 'Magnesium trisilicate compound', 'strength' => '250 mg'],
            'MED-CITAL-100SYR' => ['generic_name' => 'Sodium citrate', 'strength' => null],
            'MED-GLOBZ-200SYR' => ['generic_name' => 'Iron + Folic acid + Vitamin B12', 'strength' => null],
            'MED-HEMOV-200SYR' => ['generic_name' => 'Iron + Vitamin B complex', 'strength' => null],
            'MED-HEMAT-200SYR' => ['generic_name' => 'Iron + Folic acid + Vitamin B', 'strength' => null],
            'MED-SKTONE-100SYR' => ['generic_name' => 'Iron + Vitamin B + Folic acid + Zinc', 'strength' => null],
            'MED-MUMFER-150SYR' => ['generic_name' => 'Iron + Folic acid', 'strength' => null],
            'MED-FERR-162CAP' => ['generic_name' => 'Iron supplement', 'strength' => '162 mg'],
            'MED-WHFL-20OINT' => ['generic_name' => 'Salicylic acid + Benzoic acid', 'strength' => null],
            'MED-BPO-20GEL' => ['generic_name' => 'Benzoyl peroxide', 'strength' => '5%'],
            'MED-DEXNEO-EYEDROP' => ['generic_name' => 'Dexamethasone + Neomycin', 'strength' => null],
            'MED-NASAL-ADULT' => ['generic_name' => 'Oxymetazoline / Xylometazoline', 'strength' => null],
            'MED-NASAL-PAED' => ['generic_name' => 'Oxymetazoline / Xylometazoline (paediatric)', 'strength' => null],
            'MED-BORIC-EARDROP' => ['generic_name' => 'Boric acid', 'strength' => null],
            'MED-MICG-400PESS' => ['generic_name' => 'Miconazole nitrate', 'strength' => '400 mg'],
        ];

        foreach ($this->controlledCodes as $code) {
            $this->overrides[$code]['is_controlled_substance'] = true;
            if (str_starts_with($code, 'MED-TRAM') || str_starts_with($code, 'MED-PREG')) {
                $this->overrides[$code]['controlled_substance_schedule'] = 'schedule_IV';
            }
            if (str_starts_with($code, 'MED-DIAZ')) {
                $this->overrides[$code]['controlled_substance_schedule'] = 'schedule_IV';
            }
        }
    }

    private function deriveFields(object $item): ?array
    {
        $code = $item->code;
        $name = $item->name;
        $unit = $item->unit;

        $override = $this->overrides[$code] ?? [];

        $strength = array_key_exists('strength', $override) ? $override['strength'] : $this->parseStrength($name);
        $genericName = array_key_exists('generic_name', $override) ? $override['generic_name'] : $this->parseGenericName($name);
        $dosageForm = $this->mapUnitToDosageForm($unit, $name);
        $route = $this->mapDosageFormToRoute($dosageForm, $name);

        $storageConditions = in_array($code, $this->coldChainCodes)
            ? 'Store at 2–8°C. Do not freeze.'
            : 'Store below 25°C, protect from light.';

        $isControlled = $override['is_controlled_substance'] ?? in_array($code, $this->controlledCodes);
        $controlledSchedule = $override['controlled_substance_schedule'] ?? null;
        $groupCode = $this->toGroupCode($genericName);

        $otcAllowed = !$isControlled
            && !str_contains($genericName, 'antibiotic')
            && !str_contains($name, 'injection');
        $packSize = $this->defaultPackSize($dosageForm, $unit);
        $stockUnit = $this->defaultStockUnit($dosageForm, $name);
        $purchaseUnit = $this->defaultPurchaseUnit($dosageForm, $name);
        $purchaseUnitQuantity = $this->defaultPurchaseUnitQuantity($dosageForm);

        return [
            'generic_name' => $genericName,
            'dosage_form' => $dosageForm,
            'strength' => $strength,
            'route' => $route,
            'storage_conditions' => $storageConditions,
            'requires_cold_chain' => in_array($code, $this->coldChainCodes),
            'is_controlled_substance' => $isControlled,
            'controlled_substance_schedule' => $controlledSchedule,
            'generic_group_code' => $groupCode,
            'metadata' => json_encode([
                'strength' => $strength,
                'dosageForm' => $dosageForm,
                'route' => $route,
                'otcAllowed' => $otcAllowed,
                'packSize' => $packSize,
                'stockUnit' => $stockUnit,
                'conversionFactor' => 1,
                'purchaseUnit' => $purchaseUnit,
                'purchaseUnitQuantity' => $purchaseUnitQuantity,
            ]),
        ];
    }

    private function parseStrength(string $name): ?string
    {
        if (preg_match('/(\d[\d\/.,]*\s*(mg|mcg|g|ml|%|IU|MU))/i', $name, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function parseGenericName(string $name): string
    {
        $generic = preg_replace('/\s+\d[\d\/.,]*\s*(mg|mcg|g|ml|%|IU|MU).*$/i', '', $name);
        $generic = preg_replace('/\s*\([^)]*\)\s*$/', '', $generic);
        $generic = preg_replace('/\s+(tablet|capsule|injection|ointment|cream|gel|syrup|suspension|lotion|drops|infusion|solution)$/i', '', $generic);
        $generic = trim($generic);

        if (empty($generic)) {
            $parts = preg_split('/\s+\d/', $name);
            $generic = trim($parts[0] ?? $name);
        }

        return $generic ?: $name;
    }

    private function mapUnitToDosageForm(string $unit, string $name): string
    {
        if ($unit === 'each') {
            if (preg_match('/\b(IV\s+infusion|infusion)\b/i', $name)) return 'injection';
            if (preg_match('/\b(eye\s+(drops?|ointment)|ophthalmic)\b/i', $name)) return 'eye drops';
            if (preg_match('/\b(ear\s+drops?|otic)\b/i', $name)) return 'ear drops';
            if (preg_match('/\b(nasal\s+drops?|nasal)\b/i', $name)) return 'nasal drops';
            if (preg_match('/\bnebulisation\b/i', $name)) return 'solution';
            if (preg_match('/\binhalation\b/i', $name)) return 'solution';
            if (preg_match('/\bsuppository\b/i', $name)) return 'suppository';
            if (preg_match('/\bpessary\b/', $name)) return 'pessary';
            return 'solution';
        }

        if ($unit === 'tube') {
            if (preg_match('/\bo(e|i)nt(ment)?\b/i', $name)) return 'ointment';
            if (preg_match('/\bgel\b/i', $name)) return 'gel';
            if (preg_match('/\bcream\b/i', $name)) return 'cream';
            return 'cream';
        }

        if ($unit === 'bottle') {
            if (preg_match('/\blotion\b/i', $name)) return 'lotion';
            if (preg_match('/\bsuspension\b/i', $name)) return 'suspension';
            if (preg_match('/\bsolution\b/i', $name)) return 'solution';
            return 'syrup';
        }

        return match ($unit) {
            'tablet' => 'tablet',
            'capsule' => 'capsule',
            'ampoule' => 'injection',
            'vial' => 'injection',
            'sachet' => 'powder',
            default => 'solution',
        };
    }

    private function mapDosageFormToRoute(string $dosageForm, string $name): string
    {
        if (preg_match('/\b(IV\s+infusion|infusion)\b/i', $name)) return 'intravenous';
        if (preg_match('/\b(eye\s+(drops?|ointment)|ophthalmic)\b/i', $name)) return 'ophthalmic';
        if (preg_match('/\b(ear\s+drops?|otic)\b/i', $name)) return 'otic';
        if (preg_match('/\b(nasal\s+drops?)\b/i', $name)) return 'nasal';
        if (preg_match('/\bnebulisation\b/i', $name)) return 'inhalation';
        if (preg_match('/\binhalation\b/i', $name)) return 'inhalation';
        if (preg_match('/\bsuppository\b/i', $name)) return 'rectal';
        if (preg_match('/\bpessary\b/', $name)) return 'vaginal';

        return match ($dosageForm) {
            'tablet', 'capsule', 'syrup', 'suspension', 'solution', 'elixir', 'mixture', 'powder' => 'oral',
            'cream', 'ointment', 'gel', 'lotion' => 'topical',
            'injection' => preg_match('/\b(IV\s+infusion|infusion|IV)\b/i', $name) ? 'intravenous' : 'intramuscular',
            'eye drops' => 'ophthalmic',
            'ear drops' => 'otic',
            'nasal drops' => 'nasal',
            'suppository' => 'rectal',
            'pessary' => 'vaginal',
            'inhaler', 'spray' => 'inhalation',
            'patch' => 'transdermal',
            default => 'oral',
        };
    }

    private function toGroupCode(string $genericName): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9\s+\/-]/', '', $genericName);
        $clean = preg_replace('/[\s\/]+/', '_', trim($clean));
        return strtoupper($clean);
    }

    private function defaultPackSize(string $dosageForm, string $unit): int
    {
        return match ($dosageForm) {
            'tablet', 'capsule' => 100,
            'injection' => 10,
            default => 1,
        };
    }

    private function defaultStockUnit(string $dosageForm, string $name): string
    {
        return match ($dosageForm) {
            'tablet' => 'tablet',
            'capsule' => 'capsule',
            'cream', 'ointment', 'gel' => 'tube',
            'lotion', 'syrup', 'suspension', 'solution', 'elixir', 'mixture', 'eye drops', 'ear drops', 'nasal drops' => 'bottle',
            'injection' => 'ampoule',
            'powder' => 'sachet',
            'suppository', 'pessary' => 'each',
            'inhaler', 'spray' => 'each',
            'patch' => 'each',
            default => 'each',
        };
    }

    private function defaultPurchaseUnit(string $dosageForm, string $name): string
    {
        return match ($dosageForm) {
            'tablet', 'capsule', 'injection', 'suppository', 'pessary',
            'cream', 'ointment', 'gel', 'lotion',
            'syrup', 'suspension', 'solution', 'elixir', 'mixture',
            'eye drops', 'ear drops', 'nasal drops',
            'powder', 'inhaler', 'spray', 'patch' => 'box',
            default => 'each',
        };
    }

    private function defaultPurchaseUnitQuantity(string $dosageForm): int
    {
        return match ($dosageForm) {
            'tablet', 'capsule', 'suppository', 'pessary' => 100,
            'cream', 'ointment', 'gel', 'lotion', 'syrup', 'suspension', 'solution', 'elixir', 'mixture', 'eye drops', 'ear drops', 'nasal drops' => 12,
            'injection' => 50,
            'powder' => 50,
            'inhaler', 'spray' => 12,
            'patch' => 12,
            default => 1,
        };
    }
};
