<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryVenClassification;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Support\CatalogGovernance\CatalogPlacementAuditor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateInventoryItemRequest extends FormRequest
{
    private bool $currentItemResolved = false;

    private ?InventoryItemModel $currentItem = null;

    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'itemCode',
        'msdCode',
        'nhifCode',
        'barcode',
        'codes',
        'clinicalCatalogItemId',
        'itemName',
        'genericName',
        'dosageForm',
        'strength',
        'category',
        'subcategory',
        'venClassification',
        'abcClassification',
        'unit',
        'dispensingUnit',
        'conversionFactor',
        'binLocation',
        'manufacturer',
        'storageConditions',
        'requiresColdChain',
        'reorderLevel',
        'maxStockLevel',
        'defaultWarehouseId',
        'defaultSupplierId',
    ];

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
            'itemCode' => ['sometimes', 'string', 'max:60'],
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
            'itemName' => ['sometimes', 'string', 'max:180'],
            'genericName' => ['nullable', 'string', 'max:180'],
            'dosageForm' => ['nullable', 'string', 'max:60'],
            'strength' => ['nullable', 'string', 'max:60'],
            'category' => ['nullable', 'string', 'max:120', Rule::in(InventoryItemCategory::values())],
            'subcategory' => ['nullable', 'string', 'max:120'],
            'venClassification' => ['nullable', Rule::in(InventoryVenClassification::values())],
            'abcClassification' => ['nullable', Rule::in(['A', 'B', 'C'])],
            'unit' => ['sometimes', 'string', 'max:40'],
            'dispensingUnit' => ['nullable', 'string', 'max:40'],
            'conversionFactor' => ['nullable', 'numeric', 'gt:0'],
            'binLocation' => ['nullable', 'string', 'max:60'],
            'manufacturer' => ['nullable', 'string', 'max:180'],
            'storageConditions' => ['nullable', 'string', 'max:60'],
            'requiresColdChain' => ['nullable', 'boolean'],
            'reorderLevel' => ['sometimes', 'numeric', 'min:0'],
            'maxStockLevel' => ['nullable', 'numeric', 'min:0'],
            'defaultWarehouseId' => ['nullable', 'uuid'],
            'defaultSupplierId' => ['nullable', 'uuid'],
            'status' => ['prohibited'],
            'reason' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $requestedKeys = array_keys($this->all());
            $hasAllowedField = count(array_intersect($requestedKeys, self::ALLOWED_FIELDS)) > 0;

            if (! $hasAllowedField) {
                $validator->errors()->add('payload', 'At least one updatable field is required.');
            }

            $category = $this->resolvedCategory();
            if (! $category instanceof InventoryItemCategory) {
                return;
            }

            $storageConditions = $this->effectiveString('storageConditions', 'storage_conditions');
            $requiresColdChain = $this->effectiveBoolean('requiresColdChain', 'requires_cold_chain');
            $clinicalCatalogItemId = $this->effectiveString('clinicalCatalogItemId', 'clinical_catalog_item_id');
            $clinicalTestPayload = [
                'item_code' => $this->effectiveString('itemCode', 'item_code'),
                'item_name' => $this->effectiveString('itemName', 'item_name'),
                'category' => $category->value,
            ];

            if (app(CatalogPlacementAuditor::class)->looksLikeClinicalTest($clinicalTestPayload)) {
                $validator->errors()->add('itemName', 'This looks like a clinical test/procedure. Create CBC, urinalysis, blood culture, and similar services in Clinical Care Catalogs, not Inventory Items.');
            }

            if ($category === InventoryItemCategory::PHARMACEUTICAL && $clinicalCatalogItemId === '') {
                $validator->errors()->add('clinicalCatalogItemId', 'Select the approved medicine from Clinical Care Catalogs before saving pharmaceutical stock.');
            }

            if ($category !== InventoryItemCategory::PHARMACEUTICAL && $clinicalCatalogItemId !== '') {
                $validator->errors()->add('clinicalCatalogItemId', 'Clinical catalog linking is only valid for pharmaceutical inventory. Laboratory reagents and supplies must be saved as inventory supply items, not lab tests.');
            }

            if ($clinicalCatalogItemId !== '' && ! $this->clinicalCatalogItemIsUsable($clinicalCatalogItemId)) {
                $validator->errors()->add('clinicalCatalogItemId', 'The selected clinical medicine is not active or is outside the current facility scope.');
            }

            if ($category->requiresColdChain() && ! $requiresColdChain) {
                $validator->errors()->add('requiresColdChain', 'Cold chain must remain enabled for this category.');
            }

            if (($category->requiresExpiryTracking() || $requiresColdChain) && $storageConditions === '') {
                $validator->errors()->add('storageConditions', 'Storage conditions are required for expiry-sensitive or cold-chain inventory.');
            }

            // Inventory_MasterData_Alignment_Plan.md Phase 6 -- see StoreInventoryItemRequest
            // for the reasoning. On update this has to compare against the item's
            // *current* value, not just "is it present and true": the frontend's
            // buildItemPayload() always resends every field on every save (not just
            // what changed), so "has('requiresColdChain') && true" would reject any
            // routine edit -- even a bin location change -- to an item a
            // compliance-permissioned user had already, correctly, flagged. Only a
            // genuine transition needs the permission.
            // (is_controlled_substance/controlled_substance_schedule are validated on
            // the Clinical Catalog item instead -- see UpdateClinicalCatalogItemRequest --
            // since those columns were dropped from inventory_items in Phase 3.)
            $canManageCompliance = $this->user()?->can('inventory.procurement.manage-compliance') ?? false;
            if (! $canManageCompliance) {
                $currentRequiresColdChain = (bool) ($this->currentItem()?->requires_cold_chain ?? false);
                if ($requiresColdChain !== $currentRequiresColdChain && ! $category->requiresColdChain()) {
                    $validator->errors()->add('requiresColdChain', 'Changing cold-chain requirements for this category requires the compliance permission.');
                }
            }
        });
    }

    private function resolvedCategory(): ?InventoryItemCategory
    {
        $value = $this->input('category');
        if (is_string($value)) {
            return InventoryItemCategory::tryFrom($value);
        }

        $currentItem = $this->currentItem();

        return is_string($currentItem?->category) ? InventoryItemCategory::tryFrom($currentItem->category) : null;
    }

    private function currentItem(): ?InventoryItemModel
    {
        if ($this->currentItemResolved) {
            return $this->currentItem;
        }

        $this->currentItemResolved = true;

        $itemId = $this->route('id');
        if (! is_string($itemId) || $itemId === '') {
            return null;
        }

        $this->currentItem = InventoryItemModel::query()->find($itemId);

        return $this->currentItem;
    }

    private function effectiveBoolean(string $requestKey, string $modelKey): bool
    {
        if ($this->has($requestKey)) {
            return $this->boolean($requestKey);
        }

        return (bool) data_get($this->currentItem(), $modelKey, false);
    }

    private function effectiveString(string $requestKey, string $modelKey): string
    {
        if ($this->has($requestKey)) {
            return trim((string) $this->input($requestKey, ''));
        }

        return trim((string) data_get($this->currentItem(), $modelKey, ''));
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
