<?php

namespace App\Modules\PatientFlow\Presentation\Http\Transformers;

class VisitJourneyEntryResponseTransformer
{
    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function transform(array $entry): array
    {
        return [
            'appointmentId' => $entry['appointmentId'] ?? null,
            'serviceRequestId' => $entry['serviceRequestId'] ?? null,
            'patientId' => $entry['patientId'] ?? null,
            'patientName' => $entry['patientName'] ?? null,
            'patientNumber' => $entry['patientNumber'] ?? null,
            'department' => $entry['department'] ?? null,
            'clinicianUserId' => $entry['clinicianUserId'] ?? null,
            'consultationTakeoverCount' => $entry['consultationTakeoverCount'] ?? 0,
            'appointmentStatus' => $entry['appointmentStatus'] ?? null,
            'step' => $entry['step'] ?? null,
            'stepEnteredAt' => $entry['stepEnteredAt'] ?? null,
            'priority' => $entry['priority'] ?? null,
            'openOrders' => $entry['openOrders'] ?? [],
            'allergies' => $entry['allergies'] ?? [],
            'billingStatus' => $entry['billingStatus'] ?? null,
        ];
    }
}
