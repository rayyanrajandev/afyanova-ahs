<?php

namespace App\Modules\Pos\Presentation\Http\Requests;

use App\Modules\Pos\Domain\ValueObjects\PosRegisterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePosRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $requiresStatusReason = strtolower((string) $this->input('status')) === PosRegisterStatus::INACTIVE->value;

        return [
            'registerCode' => ['sometimes', 'required', 'string', 'max:40'],
            'registerName' => ['sometimes', 'required', 'string', 'max:120'],
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'defaultCurrencyCode' => ['sometimes', 'nullable', 'string', 'size:3'],
            'status' => ['sometimes', 'required', 'string', Rule::in(PosRegisterStatus::values())],
            'statusReason' => [
                'sometimes',
                Rule::requiredIf($requiresStatusReason),
                'nullable',
                'string',
                'max:255',
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
