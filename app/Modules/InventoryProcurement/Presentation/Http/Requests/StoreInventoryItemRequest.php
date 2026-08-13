<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryVenClassification;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseStatus;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Support\CatalogGovernance\CatalogPlacementAuditor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'itemCode' => ['required', 'string', 'max:60'],
            'msdCode' => ['nullable', 'string', 'max:60'],
            'nhifCode' => ['nullable', 'string', 'max:60'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'codes' => ['nullable', 'array'],
            'codes.LOCAL' => ['nullable', 'string', 'max:120'],
            'codes.LOINC' => ['nullable', 'string', 'max:120'],
            'codes.SNOMED_CT' => ['nullable', 'string', 'max:120'],
            'codes.GS1_GTIN' => ['nullable', 'string', 'max:120'],
            'codes.NHIF' => ['nullable', 'string', 'max:120'],
            'codes.MSD' => ['nullable', 'string', 'max:120'],
            'codes.CPT' => ['nullable', 'string', 'max:120'],
            'codes.ICD' => ['nullable', 'string', 'max:120'],
            'clinicalCatalogItemId' => ['nullable', 'uuid'],
            // itemName and unit are nullable when catalog-linked; required otherwise (enforced in withValidator)
            'itemName' => ['nullable', 'string', 'max:180'],
            'genericName' => ['nullable', 'string', 'max:180'],
            'dosageForm' => ['nullable', 'string', 'max:60'],
            'strength' => ['nullable', 'string', 'max:60'],
            'category' => ['required', 'string', 'max:120', Rule::in(InventoryItemCategory::values())],
            'subcategory' => ['nullable', 'string', 'max:120'],
            'venClassification' => ['nullable', Rule::in(InventoryVenClassification::values())],
            'abcClassification' => ['nullable', Rule::in(['A', 'B', 'C'])],
            'unit' => ['nullable', 'string', 'max:40'],
            'dispensingUnit' => ['nullable', 'string', 'max:40'],
            'conversionFactor' => ['nullable', 'numeric', 'gt:0'],
            'binLocation' => ['nullable', 'string', 'max:60'],
            'manufacturer' => ['nullable', 'string', 'max:180'],
            'storageConditions' => ['nullable', 'string', 'max:60'],
            'requiresColdChain' => ['nullable', 'boolean'],
            'reorderLevel' => ['nullable', 'numeric', 'min:0'],
            'maxStockLevel' => ['nullable', 'numeric', 'min:0'],
            'defaultWarehouseId' => ['required', 'uuid'],
            'defaultSupplierId' => ['nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $category = $this->resolvedCategory();
            if (! $category instanceof InventoryItemCategory) {
                return;
            }

            $storageConditions = trim((string) $this->input('storageConditions', ''));
            $requiresColdChain = $this->boolean('requiresColdChain');
            $clinicalCatalogItemId = trim((string) $this->input('clinicalCatalogItemId', ''));
            $hasCatalogLink = $clinicalCatalogItemId !== '';

            if (app(CatalogPlacementAuditor::class)->looksLikeClinicalTest($this->all())) {
                $validator->errors()->add('itemName', 'This looks like a clinical test/procedure. Create CBC, urinalysis, blood culture, and similar services in Clinical Care Catalogs, not Inventory Items.');
            }

            if ($category === InventoryItemCategory::PHARMACEUTICAL && ! $hasCatalogLink) {
                $validator->errors()->add('clinicalCatalogItemId', 'Select the approved medicine from Clinical Care Catalogs before creating pharmaceutical stock.');
            }

            if ($category !== InventoryItemCategory::PHARMACEUTICAL && $hasCatalogLink) {
                $validator->errors()->add('clinicalCatalogItemId', 'Clinical catalog linking is only valid for pharmaceutical inventory. Laboratory reagents and supplies must be created as inventory supply items, not lab tests.');
            }

            if ($hasCatalogLink && ! $this->clinicalCatalogItemIsUsable($clinicalCatalogItemId)) {
                $validator->errors()->add('clinicalCatalogItemId', 'The selected clinical medicine is not active or is outside the current facility scope.');
            }

            $defaultWarehouseId = trim((string) $this->input('defaultWarehouseId', ''));
            if ($defaultWarehouseId !== '' && ! $this->defaultWarehouseIsUsable($defaultWarehouseId)) {
                $validator->errors()->add('defaultWarehouseId', 'The selected warehouse is not active or is outside the current facility scope.');
            }

            // When linked to a clinical catalog, identity fields are sourced from the catalog.
            // When NOT linked, enforce required fields that the catalog would otherwise provide.
            if ($hasCatalogLink) {
                // Clear any validation errors for identity fields that the catalog provides
                if ($this->has('itemName') && trim((string) $this->input('itemName', '')) === '') {
                    $validator->errors()->forget('itemName');
                }
                if ($this->has('unit') && trim((string) $this->input('unit', '')) === '') {
                    $validator->errors()->forget('unit');
                }
            } else {
                // Enforce required identity fields when not catalog-linked
                if (trim((string) $this->input('itemName', '')) === '') {
                    $validator->errors()->add('itemName', 'The item name field is required when not linked to a clinical catalog.');
                }
                if (trim((string) $this->input('unit', '')) === '') {
                    $validator->errors()->add('unit', 'The unit field is required when not linked to a clinical catalog.');
                }
            }

            if ($category->requiresColdChain() && ! $requiresColdChain) {
                $validator->errors()->add('requiresColdChain', 'Cold chain must remain enabled for this category.');
            }

            if (($category->requiresExpiryTracking() || $requiresColdChain) && $storageConditions === '') {
                $validator->errors()->add('storageConditions', 'Storage conditions are required for expiry-sensitive or cold-chain inventory.');
            }

            // Inventory_MasterData_Alignment_Plan.md Phase 6: manage-items alone is not
            // enough to opt a non-mandatory category into cold-chain handling -- that
            // needs manage-compliance. Categories where the category itself mandates
            // cold chain (Blood Product) aren't gated here: choosing that category, not
            // this field, is the discretionary act, and it already requires manage-items.
            // (is_controlled_substance/controlled_substance_schedule are validated on
            // the Clinical Catalog item instead -- see StoreClinicalCatalogItemRequest --
            // since those columns were dropped from inventory_items in Phase 3 and
            // Pharmaceutical inventory always requires a catalog link.)
            $canManageCompliance = $this->user()?->can('inventory.procurement.manage-compliance') ?? false;
            if (! $canManageCompliance && $requiresColdChain && ! $category->requiresColdChain()) {
                $validator->errors()->add('requiresColdChain', 'Enabling cold chain for this category requires the compliance permission.');
            }
        });
    }

    private function resolvedCategory(): ?InventoryItemCategory
    {
        $value = $this->input('category');

        return is_string($value) ? InventoryItemCategory::tryFrom($value) : null;
    }

    private function defaultWarehouseIsUsable(string $warehouseId): bool
    {
        $query = InventoryWarehouseModel::query()
            ->whereKey($warehouseId)
            ->where('status', InventoryWarehouseStatus::ACTIVE->value);

        if ($this->isPlatformScopingEnabled()) {
            app(PlatformScopeQueryApplier::class)->apply($query);
        }

        return $query->exists();
    }

    private function clinicalCatalogItemIsUsable(string $clinicalCatalogItemId): bool
    {
        $query = ClinicalCatalogItemModel::query()
            ->whereKey($clinicalCatalogItemId)
            ->where('catalog_type', ClinicalCatalogType::FORMULARY_ITEM->value)
            ->where('status', ClinicalCatalogItemStatus::ACTIVE->value);

        if ($this->isPlatformScopingEnabled()) {
            app(PlatformScopeQueryApplier::class)->apply($query);
        }

        return $query->exists();
    }

    private function isPlatformScopingEnabled(): bool
    {
        $featureFlagResolver = app(FeatureFlagResolverInterface::class);

        return $featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
