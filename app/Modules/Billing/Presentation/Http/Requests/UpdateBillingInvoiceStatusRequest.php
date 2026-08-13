<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use App\Modules\Billing\Domain\ValueObjects\BillingInvoiceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillingInvoiceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $status = trim(strtolower((string) $this->input('status')));

        if ($status === BillingInvoiceStatus::ISSUED->value) {
            return $user->can('billing.invoices.issue');
        }

        if ($status === BillingInvoiceStatus::VOIDED->value) {
            return $user->can('billing.invoices.void');
        }

        if ($status === BillingInvoiceStatus::CANCELLED->value) {
            return $user->can('billing.invoices.cancel');
        }

        if (
            $status === BillingInvoiceStatus::PAID->value
            || $status === BillingInvoiceStatus::PARTIALLY_PAID->value
        ) {
            // Marking an invoice paid/partially-paid through this generic status
            // endpoint has the same financial effect as the dedicated payment
            // endpoint (it creates a real payment record — see
            // UpdateBillingInvoiceStatusUseCase) and must require the same
            // permission that endpoint already requires.
            return $user->can('billing.payments.record');
        }

        if ($status === BillingInvoiceStatus::DRAFT->value) {
            // Reverting an invoice back to draft is an edit, same permission as
            // the sibling PATCH billing/{id} (general update) route.
            return $user->can('billing.invoices.update-draft');
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(BillingInvoiceStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled,voided'],
            'paidAmount' => ['nullable', 'numeric', 'min:0'],
            'paymentPayerType' => ['nullable', 'string', Rule::in([
                'self_pay',
                'insurance',
                'employer',
                'government',
                'donor',
                'other',
            ])],
            'paymentMethod' => ['nullable', 'string', Rule::in([
                'cash',
                'mobile_money',
                'lipa_namba',
                'card',
                'bank_transfer',
                'insurance_claim',
                'cheque',
                'waiver',
                'other',
            ])],
            'paymentReference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
