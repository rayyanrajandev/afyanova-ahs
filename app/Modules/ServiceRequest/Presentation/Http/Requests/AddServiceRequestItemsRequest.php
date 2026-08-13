<?php

namespace App\Modules\ServiceRequest\Presentation\Http\Requests;

use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddServiceRequestItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service.requests.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'max:50'],
            'items.*.catalogItemId' => ['nullable', 'uuid'],
            'items.*.itemName' => ['required', 'string', 'max:255'],
            'items.*.itemCode' => ['nullable', 'string', 'max:50'],
            'items.*.serviceType' => ['required', Rule::in(ServiceRequestServiceType::values())],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'items.*.clinicalIndication' => ['nullable', 'string', 'max:1000'],
            'items.*.instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
