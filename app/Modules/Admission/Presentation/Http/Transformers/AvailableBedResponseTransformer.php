<?php

namespace App\Modules\Admission\Presentation\Http\Transformers;

class AvailableBedResponseTransformer
{
    /**
     * @param  array<string, mixed>  $bed
     */
    public static function transform(array $bed): array
    {
        return [
            'id' => $bed['id'] ?? null,
            'code' => $bed['code'] ?? null,
            'name' => $bed['name'] ?? null,
            'resourceType' => $bed['resource_type'] ?? null,
            'wardName' => $bed['ward_name'] ?? null,
            'bedNumber' => $bed['bed_number'] ?? null,
            // roomName/roomNumber are the observation-room-facing aliases of
            // the same columns — see StoreObservationRoomRequest.
            'roomName' => $bed['ward_name'] ?? null,
            'roomNumber' => $bed['bed_number'] ?? null,
            'genderRestriction' => $bed['gender_restriction'] ?? null,
            'departmentId' => $bed['department_id'] ?? null,
            'location' => $bed['location'] ?? null,
            'status' => $bed['status'] ?? null,
            'isOccupied' => (bool) ($bed['is_occupied'] ?? false),
            'occupiedByAdmissionId' => $bed['occupied_by_admission_id'] ?? null,
            'occupiedByAdmissionNumber' => $bed['occupied_by_admission_number'] ?? null,
        ];
    }
}
