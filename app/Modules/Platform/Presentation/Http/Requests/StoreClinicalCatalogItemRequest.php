<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreClinicalCatalogItemRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'facilityTier' => ['nullable', 'string', 'in:dispensary,health_centre,district_hospital,regional_hospital,zonal_referral'],
            'departmentId' => ['nullable', 'uuid', 'exists:departments,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:50'],
            'billingServiceCode' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Clinical descriptors (Inventory_MasterData_Alignment_Plan.md Phase 1).
            // Meaningful for catalogType=formulary_item; harmless-nullable for other types.
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Inventory_MasterData_Alignment_Plan.md Phase 6: this is now the primary
            // place these two fields are actually set for a formulary item (Phase 2/3
            // made them catalog-owned) -- manage-formulary alone (already required by
            // the route) is not enough to flag a drug cold-chain or controlled;  that
            // needs the narrower compliance permission.
            $canManageCompliance = $this->user()?->can('inventory.procurement.manage-compliance') ?? false;
            if ($canManageCompliance) {
                return;
            }

            if ($this->boolean('requiresColdChain')) {
                $validator->errors()->add('requiresColdChain', 'Setting cold-chain requirements requires the compliance permission.');
            }

            if ($this->boolean('isControlledSubstance')) {
                $validator->errors()->add('isControlledSubstance', 'Marking a medicine as a controlled substance requires the compliance permission.');
            }

            // refillable_without_prescription lets Direct Service reference a
            // patient's own prior dispensed order for this item without a new
            // prescription — the same class of clinical-safety decision as
            // cold-chain/controlled-substance, so it gets the same gate.
            // direct_service_eligible (whether this item is requestable via
            // Direct Service at all) is lower-stakes and stays ungated.
            if ($this->boolean('refillableWithoutPrescription')) {
                $validator->errors()->add('refillableWithoutPrescription', 'Marking a medicine refillable without a prescription requires the compliance permission.');
            }
        });
    }
}
