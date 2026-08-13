<?php

namespace App\Modules\ClaimsInsurance\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateClaimsInsuranceCaseRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'invoiceId',
        'payerType',
        'payerName',
        'payerPlanName',
        'payerReference',
        'patientInsuranceRecordId',
        'memberId',
        'policyNumber',
        'cardNumber',
        'verificationReference',
        'submittedAt',
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
            'invoiceId' => ['sometimes', 'uuid'],
            'payerType' => ['sometimes', Rule::in($this->payerTypeValues())],
            'payerName' => ['nullable', 'string', 'max:120'],
            'payerPlanName' => ['nullable', 'string', 'max:160'],
            'payerReference' => ['nullable', 'string', 'max:120'],
            'patientInsuranceRecordId' => ['nullable', 'uuid'],
            'memberId' => ['nullable', 'string', 'max:120'],
            'policyNumber' => ['nullable', 'string', 'max:120'],
            'cardNumber' => ['nullable', 'string', 'max:120'],
            'verificationReference' => ['nullable', 'string', 'max:160'],
            'submittedAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['prohibited'],
            'reason' => ['prohibited'],
            'statusReason' => ['prohibited'],
            'decisionReason' => ['prohibited'],
            'adjudicatedAt' => ['prohibited'],
            'approvedAmount' => ['prohibited'],
            'rejectedAmount' => ['prohibited'],
            'settledAmount' => ['prohibited'],
            'settledAt' => ['prohibited'],
            'settlementReference' => ['prohibited'],
            'reconciliationStatus' => ['prohibited'],
            'reconciliationNotes' => ['prohibited'],
            'reconciliationExceptionStatus' => ['prohibited'],
            'reconciliationFollowUpStatus' => ['prohibited'],
            'reconciliationFollowUpDueAt' => ['prohibited'],
            'reconciliationFollowUpNote' => ['prohibited'],
            'followUpStatus' => ['prohibited'],
            'followUpDueAt' => ['prohibited'],
            'followUpNote' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (self::ALLOWED_FIELDS as $key) {
                if ($this->has($key)) {
                    return;
                }
            }

            $validator->errors()->add('request', 'Provide at least one editable claim field.');
        });
    }

    /**
     * @return array<int, string>
     */
    private function payerTypeValues(): array
    {
        return ['self_pay', 'insurance', 'employer', 'government', 'donor', 'other'];
    }
}
