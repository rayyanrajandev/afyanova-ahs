<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Domain\ValueObjects\FacilitySubscriptionStatus;
use App\Modules\Platform\Domain\ValueObjects\SubscriptionBillingCycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateFacilitySubscriptionRequest extends FormRequest
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
            'planId' => ['required', 'uuid', 'exists:platform_subscription_plans,id'],
            'status' => ['required', Rule::in(FacilitySubscriptionStatus::values())],
            'billingCycle' => ['sometimes', Rule::in(SubscriptionBillingCycle::values())],
            'priceAmount' => ['sometimes', 'numeric', 'min:0', 'max:999999999.99'],
            'currencyCode' => ['sometimes', 'string', 'size:3'],
            'trialEndsAt' => ['nullable', 'date'],
            'currentPeriodStartsAt' => ['nullable', 'date'],
            'currentPeriodEndsAt' => ['nullable', 'date', 'after_or_equal:currentPeriodStartsAt'],
            'nextInvoiceAt' => ['nullable', 'date'],
            'gracePeriodEndsAt' => ['nullable', 'date'],
            'statusReason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $status = (string) $this->input('status', '');
            $reason = trim((string) $this->input('statusReason', ''));

            if (FacilitySubscriptionStatus::requiresReason($status) && $reason === '') {
                $validator->errors()->add(
                    'statusReason',
                    'A reason is required when subscription access is past due, suspended, or cancelled.',
                );
            }
        });
    }
}
