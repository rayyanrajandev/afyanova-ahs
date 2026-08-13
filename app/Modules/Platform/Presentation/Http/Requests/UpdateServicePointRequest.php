<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateServicePointRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'code',
        'name',
        'departmentId',
        'servicePointType',
        'location',
        'notes',
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
            'code' => ['sometimes', 'string', 'max:40'],
            'name' => ['sometimes', 'string', 'max:180'],
            'departmentId' => ['nullable', 'uuid', 'exists:departments,id'],
            'servicePointType' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
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
        });
    }
}
