<?php

namespace App\Support\ClinicalOrders;

use Illuminate\Database\Eloquent\Builder;

final class EncounterScopedOrderQuery
{
    public static function hasVisitScope(
        ?string $encounterId,
        ?string $appointmentId,
        ?string $admissionId,
    ): bool {
        return trim($encounterId ?? '') !== ''
            || trim($appointmentId ?? '') !== ''
            || trim($admissionId ?? '') !== '';
    }

    public static function applySameVisitScope(
        Builder $query,
        ?string $encounterId,
        ?string $appointmentId,
        ?string $admissionId,
    ): void {
        $normalizedEncounterId = trim($encounterId ?? '');
        $normalizedAppointmentId = trim($appointmentId ?? '');
        $normalizedAdmissionId = trim($admissionId ?? '');

        if ($normalizedEncounterId !== '') {
            $query->where('encounter_id', $normalizedEncounterId);

            return;
        }

        $query->where(function (Builder $nested) use ($normalizedAppointmentId, $normalizedAdmissionId): void {
            $nested
                ->where('appointment_id', $normalizedAppointmentId !== '' ? $normalizedAppointmentId : null)
                ->where('admission_id', $normalizedAdmissionId !== '' ? $normalizedAdmissionId : null);
        });
    }
}
