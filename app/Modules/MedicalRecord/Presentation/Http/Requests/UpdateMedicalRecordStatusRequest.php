<?php

namespace App\Modules\MedicalRecord\Presentation\Http\Requests;

use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMedicalRecordStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        if (! $user->can('medical.records.read')) {
            return false;
        }

        $status = strtolower(trim((string) $this->input('status')));

        return match ($status) {
            MedicalRecordStatus::FINALIZED->value => $user->can('medical.records.finalize'),
            MedicalRecordStatus::AMENDED->value => $user->can('medical.records.amend'),
            MedicalRecordStatus::ARCHIVED->value => $user->can('medical.records.archive'),
            default => $user->can('medical.records.finalize')
                || $user->can('medical.records.amend')
                || $user->can('medical.records.archive'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(MedicalRecordStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,amended,archived'],
        ];
    }
}
