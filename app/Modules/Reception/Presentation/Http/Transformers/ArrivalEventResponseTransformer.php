<?php

namespace App\Modules\Reception\Presentation\Http\Transformers;

class ArrivalEventResponseTransformer
{
    /**
     * @param  array<string, mixed>  $arrivalEvent
     * @return array<string, mixed>
     */
    public static function transform(array $arrivalEvent): array
    {
        return [
            'id' => $arrivalEvent['id'] ?? null,
            'appointmentId' => $arrivalEvent['appointment_id'] ?? null,
            'arrivalMode' => $arrivalEvent['arrival_mode'] ?? null,
            'arrivedAt' => $arrivalEvent['arrived_at'] ?? null,
            'recordedByUserId' => $arrivalEvent['recorded_by_user_id'] ?? null,
            'verificationNotes' => $arrivalEvent['verification_notes'] ?? null,
        ];
    }
}
