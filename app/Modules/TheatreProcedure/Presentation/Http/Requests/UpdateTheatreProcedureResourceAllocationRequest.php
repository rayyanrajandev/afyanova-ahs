<?php

namespace App\Modules\TheatreProcedure\Presentation\Http\Requests;

use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureResourceAllocationStatus;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureResourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTheatreProcedureResourceAllocationRequest extends FormRequest
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
            'resourceType' => ['sometimes', Rule::in(TheatreProcedureResourceType::values())],
            'resourceReference' => ['sometimes', 'string', 'max:180'],
            'roleLabel' => ['nullable', 'string', 'max:120'],
            'plannedStartAt' => ['sometimes', 'date'],
            'plannedEndAt' => ['sometimes', 'date'],
            'actualStartAt' => ['nullable', 'date'],
            'actualEndAt' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(TheatreProcedureResourceAllocationStatus::values())],
            'statusReason' => ['nullable', 'string', 'max:500', 'required_if:status,cancelled'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $keys = [
                'resourceType',
                'resourceReference',
                'roleLabel',
                'plannedStartAt',
                'plannedEndAt',
                'actualStartAt',
                'actualEndAt',
                'status',
                'statusReason',
                'notes',
                'metadata',
            ];

            foreach ($keys as $key) {
                if ($this->has($key)) {
                    return;
                }
            }

            $validator->errors()->add('request', 'Provide at least one editable resource allocation field.');
        });
    }
}
