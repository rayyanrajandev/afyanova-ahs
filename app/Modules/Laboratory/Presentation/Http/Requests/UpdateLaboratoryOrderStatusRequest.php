<?php

namespace App\Modules\Laboratory\Presentation\Http\Requests;

use App\Modules\Laboratory\Domain\ValueObjects\LaboratoryOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLaboratoryOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('lab.sample.collect') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(LaboratoryOrderStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled'],
            'resultSummary' => ['nullable', 'string', 'max:5000'],
            'resultParameters' => ['nullable', 'array'],
            'resultParameters.*.code' => ['required_with:resultParameters', 'string', 'max:50'],
            'resultParameters.*.name' => ['required_with:resultParameters', 'string', 'max:255'],
            'resultParameters.*.value' => ['nullable', 'string', 'max:255'],
            'resultParameters.*.unit' => ['nullable', 'string', 'max:50'],
            'resultParameters.*.flag' => ['nullable', 'string', 'in:normal,abnormal,critical,inconclusive'],
            'resultParameters.*.referenceRange' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resultParameters.*.code.required_with' => 'Each result parameter must have a code.',
            'resultParameters.*.name.required_with' => 'Each result parameter must have a name.',
        ];
    }
}
