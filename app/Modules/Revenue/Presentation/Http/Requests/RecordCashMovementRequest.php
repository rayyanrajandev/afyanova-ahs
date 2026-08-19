<?php

namespace App\Modules\Revenue\Presentation\Http\Requests;

use App\Modules\Revenue\Domain\ValueObjects\CashMovementReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(CashMovementReason::values())],
            'amountMinor' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
