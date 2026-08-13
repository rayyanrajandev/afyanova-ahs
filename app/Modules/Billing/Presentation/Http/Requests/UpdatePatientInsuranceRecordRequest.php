<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

class UpdatePatientInsuranceRecordRequest extends StorePatientInsuranceRecordRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $storeOnlyRules = ['required', 'required_without:cardNumber', 'required_without:memberId'];

        foreach (['insuranceType', 'insuranceProvider', 'memberId', 'cardNumber'] as $field) {
            $rules[$field] = array_values(array_filter(
                $rules[$field],
                static fn (mixed $rule): bool => ! is_string($rule) || ! in_array($rule, $storeOnlyRules, true),
            ));
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}
