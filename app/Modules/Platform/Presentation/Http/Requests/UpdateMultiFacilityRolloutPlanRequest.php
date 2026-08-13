<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutPlanStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMultiFacilityRolloutPlanRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'rolloutCode',
        'status',
        'targetGoLiveAt',
        'actualGoLiveAt',
        'ownerUserId',
        'metadata',
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
            'rolloutCode' => ['sometimes', 'string', 'max:60'],
            'status' => ['sometimes', Rule::in(MultiFacilityRolloutPlanStatus::values())],
            'targetGoLiveAt' => ['nullable', 'date'],
            'actualGoLiveAt' => ['nullable', 'date'],
            'ownerUserId' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'metadata' => ['nullable', 'array'],
            'rollbackRequired' => ['prohibited'],
            'rollbackReason' => ['prohibited'],
            'reason' => ['prohibited'],
            'approvalCaseReference' => ['prohibited'],
            'trainingCompletedAt' => ['prohibited'],
            'acceptanceStatus' => ['prohibited'],
            'acceptanceCaseReference' => ['prohibited'],
            'checkpoints' => ['prohibited'],
            'incidents' => ['prohibited'],
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
