<?php

namespace App\Modules\EmergencyTriage\Presentation\Http\Transformers;

class EmergencyTriageCaseTransferResponseTransformer
{
    public static function transform(array $transfer): array
    {
        return [
            'id' => $transfer['id'] ?? null,
            'emergencyTriageCaseId' => $transfer['emergency_triage_case_id'] ?? null,
            'transferNumber' => $transfer['transfer_number'] ?? null,
            'transferType' => $transfer['transfer_type'] ?? null,
            'priority' => $transfer['priority'] ?? null,
            'sourceLocation' => $transfer['source_location'] ?? null,
            'destinationLocation' => $transfer['destination_location'] ?? null,
            'destinationFacilityName' => $transfer['destination_facility_name'] ?? null,
            'acceptingClinicianUserId' => $transfer['accepting_clinician_user_id'] ?? null,
            'requestedAt' => $transfer['requested_at'] ?? null,
            'acceptedAt' => $transfer['accepted_at'] ?? null,
            'departedAt' => $transfer['departed_at'] ?? null,
            'arrivedAt' => $transfer['arrived_at'] ?? null,
            'completedAt' => $transfer['completed_at'] ?? null,
            'status' => $transfer['status'] ?? null,
            'statusReason' => $transfer['status_reason'] ?? null,
            'clinicalHandoffNotes' => $transfer['clinical_handoff_notes'] ?? null,
            'transportMode' => $transfer['transport_mode'] ?? null,
            'metadata' => $transfer['metadata'] ?? null,
            'createdAt' => $transfer['created_at'] ?? null,
            'updatedAt' => $transfer['updated_at'] ?? null,
        ];
    }
}
