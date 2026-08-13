<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateClinicalCatalogItemRequest extends FormRequest
{
    private bool $currentItemResolved = false;

    private ?ClinicalCatalogItemModel $currentItem = null;

    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'code',
        'name',
        'facilityTier',
        'departmentId',
        'category',
        'unit',
        'billingServiceCode',
        'description',
        'genericName',
        'dosageForm',
        'strength',
        'route',
        'storageConditions',
        'requiresColdChain',
        'isControlledSubstance',
        'controlledSubstanceSchedule',
        'directServiceEligible',
        'refillableWithoutPrescription',
        'genericGroupCode',
        'metadata',
        'codes',
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
            'code' => ['sometimes', 'string', 'max:100'],
            'name' => ['sometimes', 'string', 'max:255'],
            'facilityTier' => ['nullable', 'string', 'in:dispensary,health_centre,district_hospital,regional_hospital,zonal_referral'],
            'departmentId' => ['nullable', 'uuid', 'exists:departments,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:50'],
            'billingServiceCode' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'genericName' => ['nullable', 'string', 'max:180'],
            'dosageForm' => ['nullable', 'string', 'max:60'],
            'strength' => ['nullable', 'string', 'max:60'],
            'route' => ['nullable', 'string', 'max:60'],
            'storageConditions' => ['nullable', 'string', 'max:60'],
            'requiresColdChain' => ['nullable', 'boolean'],
            'isControlledSubstance' => ['nullable', 'boolean'],
            'controlledSubstanceSchedule' => ['nullable', 'string', 'max:20', 'required_if:isControlledSubstance,true'],
            'directServiceEligible' => ['nullable', 'boolean'],
            'refillableWithoutPrescription' => ['nullable', 'boolean'],
            'genericGroupCode' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
            'codes' => ['nullable', 'array'],
            'codes.LOCAL' => ['nullable', 'string', 'max:120'],
            'codes.LOINC' => ['nullable', 'string', 'max:120'],
            'codes.SNOMED_CT' => ['nullable', 'string', 'max:120'],
            'codes.NHIF' => ['nullable', 'string', 'max:120'],
            'codes.MSD' => ['nullable', 'string', 'max:120'],
            'codes.CPT' => ['nullable', 'string', 'max:120'],
            'codes.ICD' => ['nullable', 'string', 'max:120'],
            'status' => ['prohibited'],
            'statusReason' => ['prohibited'],
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

            // Inventory_MasterData_Alignment_Plan.md Phase 6 -- see
            // StoreClinicalCatalogItemRequest for the reasoning. Compares against the
            // item's *current* value, not just "is it present and true": an admin UI
            // that resends the whole form on every save (the pattern this codebase
            // already uses for inventory items) would otherwise reject any routine
            // edit to an already-flagged item. Only a genuine transition needs the
            // permission.
            $canManageCompliance = $this->user()?->can('inventory.procurement.manage-compliance') ?? false;
            if ($canManageCompliance) {
                return;
            }

            $current = $this->currentItem();

            if ($this->has('requiresColdChain')) {
                $currentRequiresColdChain = (bool) ($current?->requires_cold_chain ?? false);
                if ($this->boolean('requiresColdChain') !== $currentRequiresColdChain) {
                    $validator->errors()->add('requiresColdChain', 'Changing cold-chain requirements requires the compliance permission.');
                }
            }

            if ($this->has('isControlledSubstance')) {
                $currentIsControlledSubstance = (bool) ($current?->is_controlled_substance ?? false);
                if ($this->boolean('isControlledSubstance') !== $currentIsControlledSubstance) {
                    $validator->errors()->add('isControlledSubstance', 'Changing controlled-substance status requires the compliance permission.');
                }
            }

            if ($this->has('refillableWithoutPrescription')) {
                $currentRefillable = (bool) ($current?->refillable_without_prescription ?? false);
                if ($this->boolean('refillableWithoutPrescription') !== $currentRefillable) {
                    $validator->errors()->add('refillableWithoutPrescription', 'Changing refillable-without-prescription status requires the compliance permission.');
                }
            }
        });
    }

    private function currentItem(): ?ClinicalCatalogItemModel
    {
        if ($this->currentItemResolved) {
            return $this->currentItem;
        }

        $this->currentItemResolved = true;

        $itemId = $this->route('id');
        if (! is_string($itemId) || $itemId === '') {
            return null;
        }

        $this->currentItem = ClinicalCatalogItemModel::query()->find($itemId);

        return $this->currentItem;
    }
}
