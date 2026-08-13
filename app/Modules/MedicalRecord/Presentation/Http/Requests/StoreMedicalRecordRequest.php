<?php

namespace App\Modules\MedicalRecord\Presentation\Http\Requests;

use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordNoteType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicalRecordRequest extends FormRequest
{
    private const ICD10_CODE_PATTERN = '/^[A-Za-z][0-9]{2}(?:\.[A-Za-z0-9]{1,4})?$/';

    public function authorize(): bool
    {
        return ($this->user()?->can('medical.records.read') ?? false)
            && ($this->user()?->can('medical.records.create') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'encounterId' => ['nullable', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'appointmentReferralId' => ['nullable', 'uuid'],
            'theatreProcedureId' => ['nullable', 'uuid'],
            'authorUserId' => ['nullable', 'integer', 'exists:users,id'],
            'encounterAt' => ['required', 'date'],
            'recordType' => ['required', 'string', 'max:100', Rule::in(MedicalRecordNoteType::values())],
            'subjective' => ['nullable', 'string'],
            'objective' => ['nullable', 'string'],
            'assessment' => ['nullable', 'string'],
            'plan' => ['nullable', 'string'],
            'diagnosisCode' => ['nullable', 'string', 'max:50', 'regex:'.self::ICD10_CODE_PATTERN],
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
}
