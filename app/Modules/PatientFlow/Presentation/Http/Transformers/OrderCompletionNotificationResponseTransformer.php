<?php

namespace App\Modules\PatientFlow\Presentation\Http\Transformers;

class OrderCompletionNotificationResponseTransformer
{
    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function transform(array $entry): array
    {
        return [
            'orderType' => $entry['orderType'] ?? null,
            'orderId' => $entry['orderId'] ?? null,
            'patientId' => $entry['patientId'] ?? null,
            'patientName' => $entry['patientName'] ?? null,
            'patientNumber' => $entry['patientNumber'] ?? null,
            'appointmentId' => $entry['appointmentId'] ?? null,
            'label' => $entry['label'] ?? null,
            'completedAt' => $entry['completedAt']?->toIso8601String(),
        ];
    }
}
