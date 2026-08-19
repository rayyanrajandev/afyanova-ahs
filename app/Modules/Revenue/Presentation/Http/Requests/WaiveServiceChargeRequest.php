<?php

namespace App\Modules\Revenue\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by both ways of authorizing without payment.
 *
 * The basis is fixed by which route was called rather than sent in the body:
 * a waiver is a finance decision and an emergency override is a clinical one,
 * they are held by different roles, and letting the caller name the basis
 * would let whoever holds either permission grant both.
 */
class WaiveServiceChargeRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
