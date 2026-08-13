<?php

namespace App\Modules\Encounter\Presentation\Http\Requests;

use App\Modules\Encounter\Domain\ValueObjects\EncounterDisposition;
use App\Modules\Encounter\Domain\ValueObjects\EncounterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEncounterStatusRequest extends FormRequest
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
            EncounterStatus::CLOSED->value => $user->can('medical.records.finalize'),
            'reopened', EncounterStatus::IN_PROGRESS->value => $user->can('medical.records.amend'),
            default => $user->can('medical.records.finalize')
                || $user->can('medical.records.amend'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    EncounterStatus::CLOSED->value,
                    'reopened',
                    EncounterStatus::IN_PROGRESS->value,
                ]),
            ],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,reopened'],
            'acknowledgeCloseGaps' => ['nullable', 'boolean'],
            // Not required_if here — closing without a disposition is blocked
            // via the close-readiness checklist (a 'disposition_documented'
            // item, see GetEncounterCloseReadinessUseCase), which returns the
            // richer ENCOUNTER_CLOSE_BLOCKED payload the frontend checklist
            // dialog already knows how to render, instead of a generic
            // validation error.
            'disposition' => ['nullable', 'string', Rule::in(EncounterDisposition::values())],
            'dispositionNotes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
