<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * roomName/roomNumber map onto the facility_resources.ward_name/bed_number
 * columns via the controller's fieldMap — those columns stay named after
 * ward-bed (their original use case) rather than being renamed, since a
 * rename would touch the whole existing ward-bed registry for cosmetic
 * benefit only. This request keeps the API contract honestly named.
 */
class StoreObservationRoomRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:180'],
            'departmentId' => ['nullable', 'uuid', 'exists:departments,id'],
            'roomName' => ['required', 'string', 'max:120'],
            'roomNumber' => ['required', 'string', 'max:40'],
            'genderRestriction' => ['nullable', 'string', 'in:male,female'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'chargeableItemId' => ['nullable', 'uuid', 'exists:chargeable_items,id'],
        ];
    }
}
