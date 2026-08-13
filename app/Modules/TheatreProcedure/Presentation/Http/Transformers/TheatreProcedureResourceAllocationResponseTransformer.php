<?php

namespace App\Modules\TheatreProcedure\Presentation\Http\Transformers;

class TheatreProcedureResourceAllocationResponseTransformer
{
    public static function transform(array $allocation): array
    {
        return [
            'id' => $allocation['id'] ?? null,
            'theatreProcedureId' => $allocation['theatre_procedure_id'] ?? null,
            'resourceType' => $allocation['resource_type'] ?? null,
            'resourceReference' => $allocation['resource_reference'] ?? null,
            'roleLabel' => $allocation['role_label'] ?? null,
            'plannedStartAt' => $allocation['planned_start_at'] ?? null,
            'plannedEndAt' => $allocation['planned_end_at'] ?? null,
            'actualStartAt' => $allocation['actual_start_at'] ?? null,
            'actualEndAt' => $allocation['actual_end_at'] ?? null,
            'status' => $allocation['status'] ?? null,
            'statusReason' => $allocation['status_reason'] ?? null,
            'notes' => $allocation['notes'] ?? null,
            'metadata' => $allocation['metadata'] ?? null,
            'createdAt' => $allocation['created_at'] ?? null,
            'updatedAt' => $allocation['updated_at'] ?? null,
        ];
    }
}
