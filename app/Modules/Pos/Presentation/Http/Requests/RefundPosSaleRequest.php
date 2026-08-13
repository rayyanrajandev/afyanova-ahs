<?php

namespace App\Modules\Pos\Presentation\Http\Requests;

use App\Modules\Pos\Domain\ValueObjects\PosSaleAdjustmentReasonCode;
use App\Modules\Pos\Domain\ValueObjects\PosSalePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefundPosSaleRequest extends FormRequest
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
            'registerId' => ['required', 'uuid'],
            'refundMethod' => ['required', 'string', Rule::in(PosSalePaymentMethod::values())],
            'refundReference' => ['nullable', 'string', 'max:120'],
            'reasonCode' => ['required', 'string', Rule::in(PosSaleAdjustmentReasonCode::values())],
            'note' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
