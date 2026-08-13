<?php

namespace App\Support\CatalogGovernance;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemStatus;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CatalogPlacementAuditor
{
    /**
     * @var array<int, string>
     */
    private const MEDICINE_SUBCATEGORY_ALIASES = [
        'analgesics',
        'antibiotics',
        'antimalarials',
        'cardiovascular',
        'endocrine',
        'fluids_and_electrolytes',
        'gastrointestinal',
        'maternal_health',
        'pediatric_support',
        'respiratory',
    ];

    /**
     * @var array<int, string>
     */
    private const CLINICAL_TEST_PATTERNS = [
        'blood culture',
        'cbc',
        'complete blood count',
        'creatinine',
        'crp',
        'erythrocyte sedimentation',
        'esr',
        'glucose',
        'gram stain',
        'hba1c',
        'hbsag',
        'hemoglobin',
        'hiv',
        'liver function',
        'malaria',
        'pregnancy test',
        'rpr',
        'stool microscopy',
        'urinalysis',
        'vdrl',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function auditInventoryItems(): array
    {
        if (! Schema::hasTable('inventory_items')) {
            return [];
        }

        $selectColumns = array_values(array_filter([
            'id',
            Schema::hasColumn('inventory_items', 'tenant_id') ? 'tenant_id' : null,
            Schema::hasColumn('inventory_items', 'facility_id') ? 'facility_id' : null,
            'item_code',
            'item_name',
            'category',
            Schema::hasColumn('inventory_items', 'subcategory') ? 'subcategory' : null,
            Schema::hasColumn('inventory_items', 'clinical_catalog_item_id') ? 'clinical_catalog_item_id' : null,
            Schema::hasColumn('inventory_items', 'status') ? 'status' : null,
        ]));

        $rows = DB::table('inventory_items')
            ->select($selectColumns)
            ->orderBy('item_name')
            ->get();

        $findings = [];
        foreach ($rows as $row) {
            $category = $this->nullableText($row->category ?? null);
            $clinicalCatalogItemId = $this->nullableText($row->clinical_catalog_item_id ?? null);

            if ($category === null || ! in_array($category, InventoryItemCategory::values(), true)) {
                $findings[] = $this->finding(
                    issueCode: 'inventory.invalid_category',
                    severity: 'error',
                    sourceId: (string) $row->id,
                    summary: sprintf('Inventory item "%s" uses an invalid inventory category "%s".', $row->item_name, $category ?? 'empty'),
                    payload: $this->rowPayload($row),
                );
            }

            if ($this->looksLikeClinicalTest($row)) {
                $findings[] = $this->finding(
                    issueCode: 'inventory.clinical_test_misfiled',
                    severity: 'error',
                    sourceId: (string) $row->id,
                    summary: sprintf('Inventory item "%s" looks like a clinical test/procedure, not physical stock.', $row->item_name),
                    payload: $this->rowPayload($row),
                );
            }

            if ($clinicalCatalogItemId === null) {
                if ($category === InventoryItemCategory::PHARMACEUTICAL->value) {
                    $findings[] = $this->finding(
                        issueCode: 'inventory.pharmaceutical_missing_formulary_link',
                        severity: 'error',
                        sourceId: (string) $row->id,
                        summary: sprintf('Pharmaceutical inventory item "%s" is missing its formulary Clinical Care Catalog link.', $row->item_name),
                        payload: $this->rowPayload($row),
                    );
                }

                continue;
            }

            $linkedCatalogItem = $this->clinicalCatalogItem($clinicalCatalogItemId);

            if ($category !== InventoryItemCategory::PHARMACEUTICAL->value) {
                $findings[] = $this->finding(
                    issueCode: 'inventory.non_pharmaceutical_catalog_link',
                    severity: 'error',
                    sourceId: (string) $row->id,
                    summary: sprintf('Non-pharmaceutical inventory item "%s" has a Clinical Care Catalog link.', $row->item_name),
                    payload: [
                        ...$this->rowPayload($row),
                        'linkedCatalogItem' => $linkedCatalogItem,
                    ],
                );
            }

            if (($linkedCatalogItem['catalog_type'] ?? null) !== ClinicalCatalogType::FORMULARY_ITEM->value) {
                $findings[] = $this->finding(
                    issueCode: 'inventory.non_formulary_catalog_link',
                    severity: 'error',
                    sourceId: (string) $row->id,
                    summary: sprintf('Inventory item "%s" links to a non-formulary Clinical Care Catalog entry.', $row->item_name),
                    payload: [
                        ...$this->rowPayload($row),
                        'linkedCatalogItem' => $linkedCatalogItem,
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<string, mixed>
     */
    public function repairInventoryPlacement(): array
    {
        $before = $this->auditInventoryItems();
        $repairs = [];

        foreach ($before as $finding) {
            $sourceId = (string) ($finding['sourceId'] ?? '');
            if ($sourceId === '') {
                continue;
            }

            $payload = is_array($finding['payload'] ?? null) ? $finding['payload'] : [];
            $category = $this->nullableText($payload['category'] ?? null);
            $itemName = $this->nullableText($payload['itemName'] ?? null);

            match ($finding['issueCode'] ?? null) {
                'inventory.invalid_category' => $this->repairInvalidCategory($sourceId, $category, $repairs),
                'inventory.clinical_test_misfiled' => $this->retireMisfiledClinicalTest($sourceId, $repairs),
                'inventory.non_pharmaceutical_catalog_link',
                'inventory.non_formulary_catalog_link' => $this->clearInvalidCatalogLink($sourceId, $repairs),
                'inventory.pharmaceutical_missing_formulary_link' => $this->linkPharmaceuticalByName($sourceId, $itemName, $repairs),
                default => null,
            };
        }

        $after = $this->auditInventoryItems();
        $this->writeAuditFindings($after, $repairs === [] ? 'audited' : 'repaired');

        return [
            'before' => $before,
            'after' => $after,
            'repairs' => $repairs,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    public function writeAuditFindings(array $findings, string $resolution = 'audited'): void
    {
        if ($findings === [] || ! Schema::hasTable('catalog_integrity_audit_findings')) {
            return;
        }

        DB::table('catalog_integrity_audit_findings')->insert(array_map(
            fn (array $finding): array => [
                'id' => (string) str()->uuid(),
                'issue_code' => (string) ($finding['issueCode'] ?? 'catalog.unknown'),
                'severity' => (string) ($finding['severity'] ?? 'warning'),
                'module' => (string) ($finding['module'] ?? 'inventory'),
                'source_table' => (string) ($finding['sourceTable'] ?? 'inventory_items'),
                'source_id' => $finding['sourceId'] ?? null,
                'summary' => (string) ($finding['summary'] ?? ''),
                'payload' => json_encode($finding['payload'] ?? [], JSON_THROW_ON_ERROR),
                'resolution' => $resolution,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            $findings,
        ));
    }

    public function looksLikeClinicalTest(mixed $rowOrPayload): bool
    {
        $code = strtolower((string) data_get($rowOrPayload, 'item_code', data_get($rowOrPayload, 'itemCode', '')));
        $name = strtolower((string) data_get($rowOrPayload, 'item_name', data_get($rowOrPayload, 'itemName', '')));
        $subcategory = strtolower((string) data_get($rowOrPayload, 'subcategory', ''));
        $unit = strtolower((string) data_get($rowOrPayload, 'unit', ''));
        $category = strtolower((string) data_get($rowOrPayload, 'category', ''));
        $haystack = trim($code.' '.$name.' '.$subcategory.' '.$unit);

        if ($this->looksLikePhysicalLabStock($haystack)) {
            return false;
        }

        if ($category === InventoryItemCategory::LABORATORY->value) {
            foreach (self::CLINICAL_TEST_PATTERNS as $pattern) {
                if (str_contains($haystack, $pattern)) {
                    return true;
                }
            }
        }

        return preg_match('/\b(LAB|TEST)[-_ ]?(CBC|UA|URINALYSIS|HBA1C|HIV|VDRL|RPR)\b/i', $haystack) === 1;
    }

    private function looksLikePhysicalLabStock(string $haystack): bool
    {
        foreach ([
            'buffer',
            'calibrator',
            'cartridge',
            'cassette',
            'control',
            'cuvette',
            'kit',
            'media',
            'pipette',
            'quality control',
            'reag',
            'reagent',
            'slide',
            'stain',
            'strip',
            'swab',
            'tube',
            'vial',
        ] as $stockKeyword) {
            if (str_contains($haystack, $stockKeyword)) {
                return true;
            }
        }

        return false;
    }

    private function repairInvalidCategory(string $sourceId, ?string $category, array &$repairs): void
    {
        if ($category !== null && in_array(strtolower($category), self::MEDICINE_SUBCATEGORY_ALIASES, true)) {
            $updates = [
                'category' => InventoryItemCategory::PHARMACEUTICAL->value,
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('inventory_items', 'subcategory')) {
                $updates['subcategory'] = strtolower($category);
            }

            DB::table('inventory_items')->where('id', $sourceId)->update($updates);

            $repairs[] = [
                'sourceId' => $sourceId,
                'action' => 'moved_invalid_medicine_category_to_pharmaceutical',
            ];

            return;
        }

        $updates = [
            'category' => InventoryItemCategory::OTHER->value,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('inventory_items', 'subcategory')) {
            $updates['subcategory'] = 'needs_inventory_review';
        }
        if (Schema::hasColumn('inventory_items', 'clinical_catalog_item_id')) {
            $updates['clinical_catalog_item_id'] = null;
        }

        DB::table('inventory_items')->where('id', $sourceId)->update($updates);

        $repairs[] = [
            'sourceId' => $sourceId,
            'action' => 'moved_invalid_category_to_other_review',
        ];
    }

    private function retireMisfiledClinicalTest(string $sourceId, array &$repairs): void
    {
        // TODO: consumption recipe (phase 2)
        $updates = [
            'category' => InventoryItemCategory::OTHER->value,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('inventory_items', 'subcategory')) {
            $updates['subcategory'] = 'misfiled_clinical_test';
        }
        if (Schema::hasColumn('inventory_items', 'clinical_catalog_item_id')) {
            $updates['clinical_catalog_item_id'] = null;
        }
        if (Schema::hasColumn('inventory_items', 'status')) {
            $updates['status'] = InventoryItemStatus::INACTIVE->value;
        }

        DB::table('inventory_items')->where('id', $sourceId)->update($updates);

        $repairs[] = [
            'sourceId' => $sourceId,
            'action' => 'retired_misfiled_clinical_test_inventory_row',
        ];
    }

    private function clearInvalidCatalogLink(string $sourceId, array &$repairs): void
    {
        if (! Schema::hasColumn('inventory_items', 'clinical_catalog_item_id')) {
            return;
        }

        DB::table('inventory_items')
            ->where('id', $sourceId)
            ->update([
                'clinical_catalog_item_id' => null,
                'updated_at' => now(),
            ]);

        $repairs[] = [
            'sourceId' => $sourceId,
            'action' => 'cleared_invalid_inventory_catalog_link',
        ];
    }

    private function linkPharmaceuticalByName(string $sourceId, ?string $itemName, array &$repairs): void
    {
        if ($itemName === null) {
            return;
        }

        $catalogItemId = DB::table('platform_clinical_catalog_items')
            ->where('catalog_type', ClinicalCatalogType::FORMULARY_ITEM->value)
            ->where('status', 'active')
            ->where(function ($query) use ($itemName): void {
                $query->whereRaw('LOWER(name) = ?', [strtolower($itemName)])
                    ->orWhereRaw('LOWER(code) = ?', [strtolower($itemName)]);
            })
            ->value('id');

        if (! is_string($catalogItemId) || $catalogItemId === '') {
            return;
        }

        if (! Schema::hasColumn('inventory_items', 'clinical_catalog_item_id')) {
            return;
        }

        DB::table('inventory_items')
            ->where('id', $sourceId)
            ->update([
                'clinical_catalog_item_id' => $catalogItemId,
                'updated_at' => now(),
            ]);

        $repairs[] = [
            'sourceId' => $sourceId,
            'action' => 'linked_pharmaceutical_to_matching_formulary_item',
            'clinicalCatalogItemId' => $catalogItemId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $issueCode, string $severity, string $sourceId, string $summary, array $payload): array
    {
        return [
            'module' => 'inventory',
            'issueCode' => $issueCode,
            'severity' => $severity,
            'sourceTable' => 'inventory_items',
            'sourceId' => $sourceId,
            'summary' => $summary,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowPayload(object $row): array
    {
        return [
            'tenantId' => $row->tenant_id ?? null,
            'facilityId' => $row->facility_id ?? null,
            'itemCode' => $row->item_code ?? null,
            'itemName' => $row->item_name ?? null,
            'category' => $row->category ?? null,
            'subcategory' => $row->subcategory ?? null,
            'clinicalCatalogItemId' => $row->clinical_catalog_item_id ?? null,
            'status' => $row->status ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function clinicalCatalogItem(string $id): ?array
    {
        if (! Schema::hasTable('platform_clinical_catalog_items')) {
            return null;
        }

        $row = DB::table('platform_clinical_catalog_items')
            ->select(['id', 'catalog_type', 'code', 'name', 'status'])
            ->where('id', $id)
            ->first();

        return $row === null ? null : [
            'id' => $row->id,
            'catalogType' => $row->catalog_type,
            'catalog_type' => $row->catalog_type,
            'code' => $row->code,
            'name' => $row->name,
            'status' => $row->status,
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
