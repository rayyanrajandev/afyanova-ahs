<?php

namespace App\Modules\Revenue\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseCashierSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The counted amount, and nothing else.
     *
     * The endpoint deliberately does not accept — or return — what the ledger
     * expected. That is the blind count.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'declaredCashMinor' => ['required', 'integer', 'min:0'],
        ];
    }
}
