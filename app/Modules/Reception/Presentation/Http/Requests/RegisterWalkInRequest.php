<?php

namespace App\Modules\Reception\Presentation\Http\Requests;

use App\Modules\Reception\Domain\ValueObjects\ArrivalMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterWalkInRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('appointments.create')
            && $user->can('appointment.check-in');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'arrivalMode' => [
                'required',
                Rule::in([ArrivalMode::WALK_IN->value, ArrivalMode::EMERGENCY->value]),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
