<?php

namespace App\Modules\TheatreProcedure\Presentation\Http\Requests;

use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTheatreProcedureRequest extends FormRequest
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
            'patientId' => ['required', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'entryMode' => ['nullable', Rule::in(['draft', 'active'])],
            'orderSessionId' => ['nullable', 'uuid'],
            'serviceRequestId' => ['nullable', 'uuid'],
            'replacesOrderId' => ['nullable', 'uuid', 'prohibits:addOnToOrderId'],
            'addOnToOrderId' => ['nullable', 'uuid', 'prohibits:replacesOrderId'],
            'theatreProcedureCatalogItemId' => ['nullable', 'uuid'],
            'procedureType' => ['nullable', 'string', 'max:120', 'required_without:theatreProcedureCatalogItemId'],
            'procedureName' => ['nullable', 'string', 'max:180'],
            'operatingClinicianUserId' => ['required', 'integer', 'exists:users,id'],
            'anesthetistUserId' => ['nullable', 'integer', 'exists:users,id'],
            'theatreRoomServicePointId' => ['nullable', 'uuid', 'exists:facility_resources,id'],
            'theatreRoomName' => ['nullable', 'string', 'max:120'],
            'scheduledAt' => ['required', 'date'],
            'startedAt' => ['nullable', 'date'],
            'completedAt' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(TheatreProcedureStatus::values())],
            'statusReason' => ['nullable', 'string', 'max:500', 'required_if:status,cancelled'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
