<?php

namespace App\Modules\Reception\Presentation\Http\Transformers;

class ReceptionQueueEntryResponseTransformer
{
    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function transform(array $entry): array
    {
        return [
            'appointmentId' => $entry['appointmentId'] ?? null,
            'appointmentNumber' => $entry['appointmentNumber'] ?? null,
            'status' => $entry['status'] ?? null,
            'patientId' => $entry['patientId'] ?? null,
            'patientName' => $entry['patientName'] ?? null,
            'patientNumber' => $entry['patientNumber'] ?? null,
            'department' => $entry['department'] ?? null,
            'clinicianUserId' => $entry['clinicianUserId'] ?? null,
            'triageOwnerUserId' => $entry['triageOwnerUserId'] ?? null,
            'triageOwnerAssignedAt' => $entry['triageOwnerAssignedAt']?->toIso8601String(),
            'consultationOwnerUserId' => $entry['consultationOwnerUserId'] ?? null,
            'consultationStartedAt' => $entry['consultationStartedAt']?->toIso8601String(),
            'hasSignedConsultationNote' => $entry['hasSignedConsultationNote'] ?? false,
            'consultationStep' => $entry['consultationStep'] ?? null,
            'arrivalMode' => $entry['arrivalMode'] ?? null,
            'tier' => $entry['tier'] ?? null,
            'queuePosition' => $entry['queuePosition'] ?? null,
            'waitStartedAt' => $entry['waitStartedAt']?->toIso8601String(),
            'waitMinutes' => $entry['waitMinutes'] ?? null,
        ];
    }
}
