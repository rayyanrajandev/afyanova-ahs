<?php

namespace App\Modules\Pos\Presentation\Http\Requests;

use App\Modules\Pos\Domain\ValueObjects\PosSaleAdjustmentReasonCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VoidPosSaleRequest extends FormRequest
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
        return [
            'reasonCode' => ['required', 'string', Rule::in(PosSaleAdjustmentReasonCode::values())],
            'note' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
