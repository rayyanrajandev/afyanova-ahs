<?php

namespace App\Modules\MedicalRecord\Presentation\Http\Requests;

use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordNoteType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMedicalRecordRequest extends FormRequest
{
    private const ICD10_CODE_PATTERN = '/^[A-Za-z][0-9]{2}(?:\.[A-Za-z0-9]{1,4})?$/';

    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'patientId',
        'encounterId',
        'admissionId',
        'appointmentId',
        'appointmentReferralId',
        'theatreProcedureId',
        'authorUserId',
        'encounterAt',
        'recordType',
        'subjective',
        'objective',
        'assessment',
        'plan',
        'diagnosisCode',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can(
            'medical.records.draft.update',
            (string) $this->route('id'),
        ) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['sometimes', 'uuid'],
            'encounterId' => ['nullable', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'appointmentReferralId' => ['nullable', 'uuid'],
            'theatreProcedureId' => ['nullable', 'uuid'],
            'authorUserId' => ['nullable', 'integer', 'exists:users,id'],
            'encounterAt' => ['sometimes', 'date'],
            'recordType' => ['sometimes', 'string', 'max:100', Rule::in(MedicalRecordNoteType::values())],
            'subjective' => ['nullable', 'string'],
            'objective' => ['nullable', 'string'],
            'assessment' => ['nullable', 'string'],
            'plan' => ['nullable', 'string'],
            'diagnosisCode' => ['nullable', 'string', 'max:50', 'regex:'.self::ICD10_CODE_PATTERN],
            'expectedUpdatedAt' => ['nullable', 'date'],
            'forceDraftSave' => ['sometimes', 'boolean'],
            'status' => ['prohibited'],
            'statusReason' => ['prohibited'],
            'reason' => ['prohibited'],
            'signedByUserId' => ['prohibited'],
            'signedAt' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('diagnosisCode')) {
            $value = $this->input('diagnosisCode');
            $normalized = $value === null ? null : strtoupper(trim((string) $value));
            $merge['diagnosisCode'] = $normalized === '' ? null : $normalized;
        }

        if ($this->has('recordType')) {
            $merge['recordType'] = MedicalRecordNoteType::normalize($this->input('recordType'));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
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
