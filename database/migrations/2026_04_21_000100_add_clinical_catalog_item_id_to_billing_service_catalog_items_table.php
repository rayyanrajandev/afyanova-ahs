<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('billing_service_catalog_items', 'clinical_catalog_item_id')) {
                $table->uuid('clinical_catalog_item_id')->nullable()->after('facility_id');
                $table->index('clinical_catalog_item_id', 'billing_service_catalog_items_clinical_catalog_item_id_idx');
                $table->foreign('clinical_catalog_item_id', 'billing_service_catalog_items_clinical_catalog_item_id_fk')
                    ->references('id')
                    ->on('platform_clinical_catalog_items')
                    ->nullOnDelete();
            }
        });

        $billingRows = DB::table('billing_service_catalog_items')
            ->select(['id', 'tenant_id', 'facility_id', 'service_code', 'clinical_catalog_item_id'])
            ->get();

        foreach ($billingRows as $billingRow) {
            if ($billingRow->clinical_catalog_item_id !== null) {
                continue;
            }

            $matchedClinicalCatalogItemId = $this->findClinicalCatalogItemIdForServiceCode(
                serviceCode: (string) ($billingRow->service_code ?? ''),
                tenantId: $billingRow->tenant_id,
                facilityId: $billingRow->facility_id,
            );

            if ($matchedClinicalCatalogItemId === null) {
                continue;
            }

            DB::table('billing_service_catalog_items')
                ->where('id', $billingRow->id)
                ->update([
                    'clinical_catalog_item_id' => $matchedClinicalCatalogItemId,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            if (Schema::hasColumn('billing_service_catalog_items', 'clinical_catalog_item_id')) {
                $table->dropForeign('billing_service_catalog_items_clinical_catalog_item_id_fk');
                $table->dropIndex('billing_service_catalog_items_clinical_catalog_item_id_idx');
                $table->dropColumn('clinical_catalog_item_id');
            }
        });
    }

    private function findClinicalCatalogItemIdForServiceCode(
        string $serviceCode,
        ?string $tenantId,
        ?string $facilityId,
    ): ?string {
        $normalizedServiceCode = strtoupper(trim($serviceCode));
        if ($normalizedServiceCode === '') {
            return null;
        }

        $candidates = DB::table('platform_clinical_catalog_items')
            ->select(['id', 'metadata', 'status'])
            ->when(
                $tenantId === null,
                fn ($query) => $query->whereNull('tenant_id'),
                fn ($query) => $query->where('tenant_id', $tenantId),
            )
            ->when(
                $facilityId === null,
                fn ($query) => $query->whereNull('facility_id'),
                fn ($query) => $query->where('facility_id', $facilityId),
            )
            ->where('status', 'active')
            ->get();

        foreach ($candidates as $candidate) {
            $metadata = $this->decodeMetadata($candidate->metadata ?? null);
            $linkedServiceCode = strtoupper(trim((string) ($metadata['billingServiceCode'] ?? $metadata['billing_service_code'] ?? '')));

            if ($linkedServiceCode === $normalizedServiceCode) {
                return (string) $candidate->id;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
