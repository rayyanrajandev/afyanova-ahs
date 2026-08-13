<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $items = DB::table('platform_clinical_catalog_items')
            ->where('catalog_type', 'formulary_item')
            ->get(['id', 'code', 'metadata']);

        foreach ($items as $item) {
            $metadata = $this->normalizeMetadata($item->metadata ?? null);

            $metadata['formularyStatus'] = $metadata['formularyStatus'] ?? 'formulary';
            $metadata['reviewMode'] = $metadata['reviewMode'] ?? 'auto_formulary';
            $metadata['substitutionAllowed'] = array_key_exists('substitutionAllowed', $metadata)
                ? (bool) $metadata['substitutionAllowed']
                : false;

            if ($item->code === 'MED-CEFTR-1GINJ') {
                $metadata['reviewMode'] = 'policy_review_required';
                $metadata['restrictionReason'] = 'Broad-spectrum injectable antibiotic. Review indication and release path before dispensing.';
                $metadata['allowedIndicationKeywords'] = [
                    'severe infection',
                    'sepsis',
                    'meningitis',
                    'pneumonia',
                    'pelvic infection',
                ];
            }

            if ($item->code === 'MED-OXYT-10INJ') {
                $metadata['reviewMode'] = 'policy_review_required';
                $metadata['restrictionReason'] = 'Restricted to maternity and postpartum hemorrhage workflows with explicit clinical justification.';
                $metadata['allowedIndicationKeywords'] = [
                    'postpartum',
                    'hemorrhage',
                    'haemorrhage',
                    'labor',
                    'induction',
                ];
            }

            DB::table('platform_clinical_catalog_items')
                ->where('id', $item->id)
                ->update([
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $items = DB::table('platform_clinical_catalog_items')
            ->where('catalog_type', 'formulary_item')
            ->get(['id', 'metadata']);

        foreach ($items as $item) {
            $metadata = $this->normalizeMetadata($item->metadata ?? null);

            unset(
                $metadata['formularyStatus'],
                $metadata['reviewMode'],
                $metadata['substitutionAllowed'],
                $metadata['restrictionReason'],
                $metadata['allowedIndicationKeywords'],
            );

            DB::table('platform_clinical_catalog_items')
                ->where('id', $item->id)
                ->update([
                    'metadata' => $metadata === []
                        ? null
                        : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMetadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
};
