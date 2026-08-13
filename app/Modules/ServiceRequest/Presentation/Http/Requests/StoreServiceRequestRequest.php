<?php

namespace App\Modules\ServiceRequest\Presentation\Http\Requests;

use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service.requests.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('departmentId') && $this->has('department_id')) {
            $this->merge(['departmentId' => $this->input('department_id')]);
        }

        $departmentId = $this->input('departmentId');
        if ($departmentId === '') {
            $this->merge(['departmentId' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'appointmentId' => ['nullable', 'uuid', Rule::exists('appointments', 'id')->where(
                fn ($query) => $query->where('patient_id', (string) $this->input('patientId')),
            )],
            'departmentId' => ['nullable', 'uuid', Rule::exists('departments', 'id')->where('status', 'active')],
            'serviceType' => ['required', Rule::in(ServiceRequestServiceType::values())],
            'priority' => ['nullable', Rule::in(['routine', 'urgent'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Direct Service item entry: catalogItemId is required (not
            // nullable) so an item can never be created without a real
            // catalog link — that's what previously let items silently skip
            // fulfillment (FulfillServiceRequestItemsUseCase treats a null
            // catalog_item_id as "not ready" and drops it with no error).
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.catalogItemId' => ['required_with:items', 'uuid', 'exists:platform_clinical_catalog_items,id'],
            'items.*.itemName' => ['required_with:items', 'string', 'max:255'],
            'items.*.itemCode' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'items.*.clinicalIndication' => ['nullable', 'string', 'max:1000'],
            'items.*.referencePharmacyOrderId' => ['nullable', 'uuid', 'exists:pharmacy_orders,id'],
        ];
    }
}
