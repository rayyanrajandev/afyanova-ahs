<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

class FacilityResourceResponseTransformer
{
    public static function transform(array $resource): array
    {
        return [
            'id' => $resource['id'] ?? null,
            'tenantId' => $resource['tenant_id'] ?? null,
            'facilityId' => $resource['facility_id'] ?? null,
            'resourceType' => $resource['resource_type'] ?? null,
            'code' => $resource['code'] ?? null,
            'name' => $resource['name'] ?? null,
            'departmentId' => $resource['department_id'] ?? null,
            'servicePointType' => $resource['service_point_type'] ?? null,
            'wardName' => $resource['ward_name'] ?? null,
            'bedNumber' => $resource['bed_number'] ?? null,
            // roomName/roomNumber are the observation-room-facing aliases of
            // the same ward_name/bed_number columns — see
            // StoreObservationRoomRequest for why the columns aren't renamed.
            'roomName' => $resource['ward_name'] ?? null,
            'roomNumber' => $resource['bed_number'] ?? null,
            'genderRestriction' => $resource['gender_restriction'] ?? null,
            'location' => $resource['location'] ?? null,
            'chargeableItemId' => $resource['chargeable_item_id'] ?? null,
            'status' => $resource['status'] ?? null,
            'statusReason' => $resource['status_reason'] ?? null,
            'notes' => $resource['notes'] ?? null,
            'createdAt' => $resource['created_at'] ?? null,
            'updatedAt' => $resource['updated_at'] ?? null,
        ];
    }
}

