<?php

namespace App\Modules\TheatreProcedure\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTheatreProcedureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('theatre.procedures.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['prohibited'],
            'admissionId' => ['prohibited'],
            'appointmentId' => ['prohibited'],
            'orderSessionId' => ['prohibited'],
            'replacesOrderId' => ['prohibited'],
            'addOnToOrderId' => ['prohibited'],
            'entryMode' => ['prohibited'],
            'theatreProcedureCatalogItemId' => ['sometimes', 'nullable', 'uuid'],
            'procedureType' => ['sometimes', 'string', 'max:120'],
            'procedureName' => ['prohibited'],
            'operatingClinicianUserId' => ['sometimes', 'integer', 'exists:users,id'],
            'anesthetistUserId' => ['nullable', 'integer', 'exists:users,id'],
            'theatreRoomServicePointId' => ['nullable', 'uuid', 'exists:facility_resources,id'],
            'theatreRoomName' => ['nullable', 'string', 'max:120'],
            'scheduledAt' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['prohibited'],
            'statusReason' => ['prohibited'],
            'startedAt' => ['prohibited'],
            'completedAt' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $keys = [
                'theatreProcedureCatalogItemId',
                'procedureType',
                'operatingClinicianUserId',
                'anesthetistUserId',
                'theatreRoomServicePointId',
                'theatreRoomName',
                'scheduledAt',
                'notes',
            ];

            foreach ($keys as $key) {
                if ($this->has($key)) {
                    return;
                }
            }

            $validator->errors()->add('request', 'Provide at least one editable theatre procedure field.');
        });
    }
}
