<?php

namespace App\Modules\ServiceRequest\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateServiceRequestStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service.requests.update-status') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['in_progress', 'completed', 'cancelled'])],
            'statusReason' => ['nullable', 'string', 'max:500'],
            'linkedOrderNumber' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $status = (string) $this->input('status', '');
            $reason = trim((string) $this->input('statusReason', ''));

            if (in_array($status, ['completed', 'cancelled'], true) && $reason === '') {
                $validator->errors()->add('statusReason', 'A reason is required when closing or cancelling a direct service ticket.');
            }

            if ($status === 'completed') {
                $linkedNumber = trim((string) $this->input('linkedOrderNumber', ''));
                if ($linkedNumber === '') {
                    $validator->errors()->add('linkedOrderNumber', 'A destination order number is required when closing a direct service ticket.');
                }
            }
        });
    }
}
