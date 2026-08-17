<?php

namespace App\Support\ClinicalOrders;

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\PatientFlow\Application\UseCases\ResolveVisitStagesUseCase;

/**
 * Attaches `visitStage` — where the patient stands in the overall visit — to
 * transformed clinical orders, batched, in the shape
 * ClinicalOrderPatientSummaryEnricher already established.
 *
 * The Laboratory workspace could see its own order status but nothing about the
 * visit around it, so a technician had no way to tell a specimen from a patient
 * who is still sitting in the waiting room from one whose doctor is holding a
 * consultation open for the result (reports/laboratory-workspace-flow-plan.md,
 * phase 4).
 *
 * Resolved by ResolveVisitStagesUseCase — the single place that reconciles an
 * appointment's own step with its open diagnostic orders — rather than a
 * lab-local rule that would drift from what reception and clinician display.
 */
final class ClinicalOrderVisitStageEnricher
{
    /**
     * @param  list<array<string, mixed>>  $rawOrders
     * @param  list<array<string, mixed>>  $transformedOrders
     * @return list<array<string, mixed>>
     */
    public static function attachToTransformedOrders(array $rawOrders, array $transformedOrders): array
    {
        $stages = self::stagesByAppointmentId($rawOrders);

        return array_map(static function (array $order) use ($stages): array {
            $appointmentId = trim((string) ($order['appointmentId'] ?? ''));

            return array_merge($order, [
                // Null for a direct-service order with no appointment: that
                // patient has no visit stage to report, which is the honest
                // answer rather than a guess.
                'visitStage' => $appointmentId !== '' ? ($stages[$appointmentId] ?? null) : null,
            ]);
        }, $transformedOrders);
    }

    /**
     * @param  list<array<string, mixed>>  $rawOrders
     * @return array<string, string|null>
     */
    private static function stagesByAppointmentId(array $rawOrders): array
    {
        $appointmentIds = [];

        foreach ($rawOrders as $order) {
            $appointmentId = trim((string) ($order['appointment_id'] ?? ''));
            if ($appointmentId !== '') {
                $appointmentIds[$appointmentId] = true;
            }
        }

        if ($appointmentIds === []) {
            return [];
        }

        $appointments = AppointmentModel::query()
            ->whereIn('id', array_keys($appointmentIds))
            ->get([
                'id',
                'status',
                'triage_owner_user_id',
                'consultation_started_at',
                'nursing_contact_user_id',
            ])
            ->all();

        // The reconciliation itself lives in ResolveVisitStagesUseCase — one
        // place decides how an appointment's own step and its open diagnostic
        // orders combine, so the lab worklist, the clinician queue and the
        // patient profile can never disagree about the same patient.
        return app(ResolveVisitStagesUseCase::class)->forAppointments($appointments);
    }
}
