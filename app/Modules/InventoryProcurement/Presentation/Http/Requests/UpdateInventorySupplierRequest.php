<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateInventorySupplierRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'supplierCode',
        'supplierName',
        'tinNumber',
        'bankAccountNumber',
        'lipaNumber',
        'contactPerson',
        'phone',
        'email',
        'addressLine',
        'countryCode',
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
            'supplierCode' => ['sometimes', 'string', 'max:40'],
            'supplierName' => ['sometimes', 'string', 'max:180'],
            'tinNumber' => ['nullable', 'string', 'max:30'],
            'bankAccountNumber' => ['nullable', 'string', 'max:80'],
            'lipaNumber' => ['nullable', 'string', 'max:80'],
            'contactPerson' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'addressLine' => ['nullable', 'string', 'max:2000'],
            'countryCode' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:5000'],
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
