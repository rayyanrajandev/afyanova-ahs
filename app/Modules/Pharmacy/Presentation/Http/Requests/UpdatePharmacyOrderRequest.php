<?php

namespace App\Modules\Pharmacy\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePharmacyOrderRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'orderedAt',
        'approvedMedicineCatalogItemId',
        'medicationCode',
        'dosageInstruction',
        'doseQuantity',
        'doseUnit',
        'route',
        'frequency',
        'durationValue',
        'durationUnit',
        'infusionRateValue',
        'infusionRateUnit',
        'infusionDurationValue',
        'infusionDurationUnit',
        'clinicalIndication',
        'quantityPrescribed',
        'prescribedUnit',
        'dispensingNotes',
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
            'patientId' => ['prohibited'],
            'admissionId' => ['prohibited'],
            'appointmentId' => ['prohibited'],
            'orderedByUserId' => ['prohibited'],
            'orderedAt' => ['sometimes', 'date'],
            'approvedMedicineCatalogItemId' => ['sometimes', 'nullable', 'uuid'],
            'medicationCode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'medicationName' => ['prohibited'],
            'dosageInstruction' => ['sometimes', 'string', 'max:1000'],
            'doseQuantity' => ['sometimes', 'nullable', 'numeric', 'min:0.0001'],
            'doseUnit' => ['sometimes', 'nullable', 'string', 'max:40'],
            'route' => ['sometimes', 'nullable', 'string', 'max:60'],
            'frequency' => ['sometimes', 'nullable', 'string', 'max:120'],
            'durationValue' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'durationUnit' => ['sometimes', 'nullable', 'string', 'max:40'],
            'infusionRateValue' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'infusionRateUnit' => ['sometimes', 'nullable', 'string', 'max:40'],
            'infusionDurationValue' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'infusionDurationUnit' => ['sometimes', 'nullable', 'string', 'max:40'],
            'clinicalIndication' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantityPrescribed' => ['sometimes', 'numeric', 'min:0.01'],
            'prescribedUnit' => ['sometimes', 'nullable', 'string', 'max:40'],
            'quantityDispensed' => ['prohibited'],
            'dispensedUnit' => ['prohibited'],
            'dispensingNotes' => ['nullable', 'string', 'max:2000'],
            'entryMode' => ['prohibited'],
            'orderSessionId' => ['prohibited'],
            'replacesOrderId' => ['prohibited'],
            'addOnToOrderId' => ['prohibited'],
            'status' => ['prohibited'],
            'statusReason' => ['prohibited'],
            'reason' => ['prohibited'],
            'formularyDecisionStatus' => ['prohibited'],
            'formularyDecisionReason' => ['prohibited'],
            'substitutionAllowed' => ['prohibited'],
            'substitutionMade' => ['prohibited'],
            'substitutedMedicationCode' => ['prohibited'],
            'substitutedMedicationName' => ['prohibited'],
            'substitutionReason' => ['prohibited'],
            'reconciliationStatus' => ['prohibited'],
            'reconciliationNote' => ['prohibited'],
            'verificationNote' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $requestKeys = array_keys($this->all());
            $hasAllowedFields = count(array_intersect($requestKeys, self::ALLOWED_FIELDS)) > 0;

            if (! $hasAllowedFields) {
                $validator->errors()->add('payload', 'At least one updatable field is required.');
            }
        });
    }
}
