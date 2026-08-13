<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBillingInvoiceRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'patientId',
        'admissionId',
        'appointmentId',
        'billingPayerContractId',
        'autoPriceLineItems',
        'issuedByUserId',
        'invoiceDate',
        'currencyCode',
        'subtotalAmount',
        'discountAmount',
        'taxAmount',
        'paidAmount',
        'paymentDueAt',
        'notes',
        'lineItems',
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $lineItems = $this->input('lineItems');
        if (! is_array($lineItems)) {
            return;
        }

        $this->merge([
            'lineItems' => array_map(function (array $item): array {
                return array_map(function (mixed $value): mixed {
                    return $value === '' ? null : $value;
                }, $item);
            }, $lineItems),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['sometimes', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'billingPayerContractId' => ['nullable', 'uuid', 'exists:billing_payer_contracts,id'],
            'autoPriceLineItems' => ['nullable', 'boolean'],
            'issuedByUserId' => ['nullable', 'integer', 'exists:users,id'],
            'invoiceDate' => ['sometimes', 'date'],
            'currencyCode' => ['sometimes', 'string', 'size:3'],
            'subtotalAmount' => ['sometimes', 'numeric', 'min:0'],
            'discountAmount' => ['nullable', 'numeric', 'min:0'],
            'taxAmount' => ['nullable', 'numeric', 'min:0'],
            'paidAmount' => ['nullable', 'numeric', 'min:0'],
            'paymentDueAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lineItems' => ['nullable', 'array', 'max:100'],
            'lineItems.*.description' => ['required', 'string', 'max:255'],
            'lineItems.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lineItems.*.unitPrice' => ['required', 'numeric', 'min:0'],
            'lineItems.*.serviceCode' => ['nullable', 'string', 'max:100'],
            'lineItems.*.departmentId' => ['nullable', 'uuid'],
            'lineItems.*.department' => ['nullable', 'string', 'max:120'],
            'lineItems.*.unit' => ['nullable', 'string', 'max:50'],
            'lineItems.*.notes' => ['nullable', 'string', 'max:500'],
            'lineItems.*.sourceWorkflowKind' => ['nullable', 'string', 'in:appointment_consultation,laboratory_order,pharmacy_order,radiology_order,clinical_procedure_order,theatre_procedure,admission_bed_day'],
            'lineItems.*.sourceWorkflowId' => ['nullable', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(:[1-9][0-9]*)?$/i'],
            'lineItems.*.sourceWorkflowLabel' => ['nullable', 'string', 'max:255'],
            'lineItems.*.sourcePerformedAt' => ['nullable', 'date'],
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

            $autoPriceLineItems = (bool) $this->input('autoPriceLineItems', false);
            if (! $autoPriceLineItems) {
                return;
            }

            $lineItems = $this->input('lineItems');
            if (! is_array($lineItems) || $lineItems === []) {
                $validator->errors()->add('lineItems', 'lineItems is required when autoPriceLineItems is enabled.');

                return;
            }

            foreach ($lineItems as $lineItem) {
                $serviceCode = trim((string) ($lineItem['serviceCode'] ?? ''));
                if ($serviceCode === '') {
                    $validator->errors()->add('lineItems', 'Every line item requires serviceCode when autoPriceLineItems is enabled.');
                    break;
                }
            }
        });
    }
}
