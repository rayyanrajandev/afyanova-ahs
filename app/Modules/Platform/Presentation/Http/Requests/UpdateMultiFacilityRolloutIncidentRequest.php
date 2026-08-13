<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutIncidentSeverity;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutIncidentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMultiFacilityRolloutIncidentRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'severity',
        'status',
        'summary',
        'details',
        'escalatedTo',
        'resolvedAt',
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
            'severity' => ['sometimes', Rule::in(MultiFacilityRolloutIncidentSeverity::values())],
            'status' => ['sometimes', Rule::in(MultiFacilityRolloutIncidentStatus::values())],
            'summary' => ['sometimes', 'string', 'max:200'],
            'details' => ['nullable', 'string', 'max:4000'],
            'escalatedTo' => ['nullable', 'string', 'max:200'],
            'resolvedAt' => ['nullable', 'date'],
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
