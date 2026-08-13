<?php

namespace App\Modules\Reception\Presentation\Http\Transformers;

class TriageCompletedEntryResponseTransformer
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
            'triagedAt' => $entry['triagedAt']?->toIso8601String(),
            'triageOwnerUserId' => $entry['triageOwnerUserId'] ?? null,
        ];
    }
}
