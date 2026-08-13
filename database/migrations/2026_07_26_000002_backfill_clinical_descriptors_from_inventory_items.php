<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Inventory_MasterData_Alignment_Plan.md, Phase 1 backfill. For every
 * formulary_item catalog row, copies the clinical descriptors from its
 * linked inventory_items rows into the typed columns added by
 * 2026_07_26_000001. Inventory-side data is treated as the more complete,
 * pharmacist-curated dataset historically (per the plan). Where linked
 * inventory rows disagree on a field, that field is left null and the
 * conflict is logged for manual review rather than guessed at -- per the
 * plan's "ambiguous data logs and stops" testing/rollback principle.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalogItems = DB::table('platform_clinical_catalog_items')
            ->where('catalog_type', 'formulary_item')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->get();

        $conflictCount = 0;
        $backfilledCount = 0;

        foreach ($catalogItems->chunk(200) as $chunk) {
            foreach ($chunk as $catalogItem) {
                $linkedInventoryRows = DB::table('inventory_items')
                    ->where('clinical_catalog_item_id', $catalogItem->id)
                    ->select([
                        'generic_name', 'dosage_form', 'strength', 'storage_conditions',
                        'requires_cold_chain', 'is_controlled_substance', 'controlled_substance_schedule',
                    ])
                    ->get();

                if ($linkedInventoryRows->isEmpty()) {
                    continue;
                }

                $update = [];
                foreach (['generic_name', 'dosage_form', 'strength', 'storage_conditions', 'controlled_substance_schedule'] as $field) {
                    $resolved = $this->resolveAgreedStringValue($linkedInventoryRows, $field, $catalogItem->id, $conflictCount);
                    if ($resolved !== null) {
                        $update[$field] = $resolved;
                    }
                }

                foreach (['requires_cold_chain', 'is_controlled_substance'] as $field) {
                    $resolved = $this->resolveAgreedBoolValue($linkedInventoryRows, $field, $catalogItem->id, $conflictCount);
                    if ($resolved !== null) {
                        $update[$field] = $resolved;
                    }
                }

                if (! isset($update['generic_name']) || $update['generic_name'] === null) {
                    $strength = $update['strength'] ?? null;
                    $update['generic_name'] = $this->deriveGenericName((string) $catalogItem->name, is_string($strength) ? $strength : '');
                }

                if ($update !== []) {
                    DB::table('platform_clinical_catalog_items')
                        ->where('id', $catalogItem->id)
                        ->update($update);
                    $backfilledCount++;
                }
            }
        }

        Log::info('Phase 1 clinical-descriptor backfill complete.', [
            'catalogItemsBackfilled' => $backfilledCount,
            'fieldConflictsSkipped' => $conflictCount,
        ]);
    }

    public function down(): void
    {
        // Intentionally a no-op: reversing would discard real clinical data
        // this migration backfilled from inventory_items, and there is no
        // way to distinguish values it set from values already present.
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    private function resolveAgreedStringValue(\Illuminate\Support\Collection $rows, string $field, string $catalogItemId, int &$conflictCount): ?string
    {
        $distinctValues = $rows->pluck($field)
            ->filter(static fn ($value) => is_string($value) && trim($value) !== '')
            ->map(static fn ($value) => trim($value))
            ->unique();

        if ($distinctValues->isEmpty()) {
            return null;
        }

        if ($distinctValues->count() > 1) {
            $conflictCount++;
            Log::warning('Phase 1 backfill: linked inventory items disagree on a clinical field, left null for manual review.', [
                'clinicalCatalogItemId' => $catalogItemId,
                'field' => $field,
                'candidateValues' => $distinctValues->values()->all(),
            ]);

            return null;
        }

        return $distinctValues->first();
    }

    /**
     * Returns the agreed true/false when every linked inventory row agrees (a real,
     * meaningful signal worth writing), or null when rows conflict (left for manual
     * review, per the plan's "ambiguous data logs and stops" principle) -- never
     * defaults to false, since false and "no opinion yet" must stay distinguishable
     * for the Phase 2 catalog-first read cutover to fall back correctly.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    private function resolveAgreedBoolValue(\Illuminate\Support\Collection $rows, string $field, string $catalogItemId, int &$conflictCount): ?bool
    {
        $distinctValues = $rows->pluck($field)
            ->map(fn ($value) => $this->normalizeBool($value))
            ->unique();

        if ($distinctValues->count() > 1) {
            $conflictCount++;
            Log::warning('Phase 1 backfill: linked inventory items disagree on a compliance flag, left null for manual review.', [
                'clinicalCatalogItemId' => $catalogItemId,
                'field' => $field,
                'candidateValues' => $distinctValues->values()->all(),
            ]);

            return null;
        }

        return $distinctValues->first();
    }

    /**
     * Query-builder results for a Postgres boolean column can come back as PHP bool,
     * or as the strings "t"/"f" depending on PDO driver options -- a plain (bool)
     * cast on the string "f" would wrongly evaluate to true. Normalize explicitly
     * rather than trust the cast.
     */
    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 't', 'true', 'yes'], true);
    }

    /**
     * Mirrors genericNameFromClinicalName() in
     * resources/js/pages/inventory-procurement/stock-control/IndexV2.vue --
     * strips a trailing strength token, then any trailing "123..." token,
     * from a catalog name like "Paracetamol 500mg" to get "Paracetamol".
     */
    private function deriveGenericName(string $name, string $strength): string
    {
        $withoutStrength = $name;
        if (trim($strength) !== '') {
            $pattern = '/\s*'.preg_quote(trim($strength), '/').'\s*$/i';
            $withoutStrength = trim((string) preg_replace($pattern, '', $name));
        }

        $withoutTrailingNumber = trim((string) preg_replace('/\s+\d+.*$/u', '', $withoutStrength));

        return $withoutTrailingNumber !== '' ? $withoutTrailingNumber : trim($name);
    }
};
