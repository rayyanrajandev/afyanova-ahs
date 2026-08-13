<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChargeableItemRequest extends FormRequest
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
            'catalogType' => ['required', Rule::in([
                'lab_test',
                'radiology_procedure',
                'theatre_procedure',
                'clinical_procedure',
                'formulary_item',
                'consultation',
                'bed_day',
            ])],
            'chargeModel' => ['required', Rule::in(['flat', 'per_unit', 'per_day', 'per_hour'])],
            'clinicalCatalogItemId' => ['nullable', 'uuid', 'exists:platform_clinical_catalog_items,id'],
            'code' => ['required_without:clinicalCatalogItemId', 'nullable', 'string', 'max:100'],
            'name' => ['required_without:clinicalCatalogItemId', 'nullable', 'string', 'max:255'],
            'departmentId' => ['nullable', 'uuid', 'exists:departments,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'defaultUnit' => ['nullable', 'string', 'max:50'],
            'currencyCode' => ['required', 'string', 'size:3'],
            'unitPrice' => ['required', 'numeric', 'min:0'],
            'taxRatePercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'isTaxable' => ['nullable', 'boolean'],
            'effectiveFrom' => ['nullable', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after:effectiveFrom'],
        ];
    }
}
