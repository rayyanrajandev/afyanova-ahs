<?php

namespace App\Modules\Radiology\Presentation\Http\Requests;

use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRadiologyOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('imaging.perform') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(RadiologyOrderStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled'],
            'reportSummary' => ['nullable', 'string', 'max:5000', 'required_if:status,completed'],
            // Optional, deliberately. Booking a study *is* the ordered ->
            // scheduled transition, so the slot may ride along with it rather
            // than needing a second call to the generic edit route. It is not
            // required: a department that books into an open list rather than a
            // named time still moves studies to `scheduled`, and the existing
            // workflow tests encode exactly that.
            'scheduledFor' => ['nullable', 'date'],
        ];
    }
}
