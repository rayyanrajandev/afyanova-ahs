<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateStaffDocumentRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'documentType',
        'title',
        'description',
        'issuedAt',
        'expiresAt',
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
            'documentType' => ['sometimes', 'string', 'max:60'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'issuedAt' => ['sometimes', 'nullable', 'date'],
            'expiresAt' => ['sometimes', 'nullable', 'date', 'after_or_equal:issuedAt'],
            'status' => ['prohibited'],
            'reason' => ['prohibited'],
            'statusReason' => ['prohibited'],
            'verificationStatus' => ['prohibited'],
            'verificationReason' => ['prohibited'],
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
