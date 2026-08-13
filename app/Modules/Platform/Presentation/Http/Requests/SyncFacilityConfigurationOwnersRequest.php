<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SyncFacilityConfigurationOwnersRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'operationsOwnerUserId',
        'clinicalOwnerUserId',
        'administrativeOwnerUserId',
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
            'operationsOwnerUserId' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'clinicalOwnerUserId' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'administrativeOwnerUserId' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $requestedKeys = array_keys($this->all());
            $hasAllowedField = count(array_intersect($requestedKeys, self::ALLOWED_FIELDS)) > 0;

            if (! $hasAllowedField) {
                $validator->errors()->add('payload', 'At least one owner field is required.');
            }
        });
    }
}
