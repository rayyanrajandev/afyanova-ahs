<?php

namespace App\Modules\PatientFlow\Application\UseCases;

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Laboratory\Domain\ValueObjects\LaboratoryOrderStatus;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\ClinicalProcedure\Domain\ValueObjects\ClinicalProcedureOrderStatus;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;
use Illuminate\Support\Collection;

/**
 * Phase 4 (Mode B) of reports/queue-based-workflow-modernization-plan.md.
 * Live query, same discipline as GetActiveVisitJourneyUseCase: "completed
 * order, ordered by this clinician, whose visit is still active" needs no
 * new persisted "acknowledged" field — the moment the visit itself is
 * closed (AppointmentStatus leaves the active set), the order naturally
 * drops out of this list. Nothing here is a second copy of anything.
 *
 * Gated by config/patient_flow_automation.php's mode_b_notifications flag,
 * checked here rather than in the controller — the same place
 * CreateSkeletonEmergencyTriageCase checks its own Mode C flag, so the
 * route/controller stay identical whether or not the feature is enabled.
 */
class GetOrderCompletionNotificationsForClinicianUseCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(int $clinicianUserId): array
    {
        if (! (bool) config('patient_flow_automation.mode_b_notifications.enabled')) {
            return [];
        }

        $activeAppointmentIds = AppointmentModel::query()
            ->whereIn('status', GetActiveVisitJourneyUseCase::ACTIVE_APPOINTMENT_STATUSES)
            ->pluck('id');

        if ($activeAppointmentIds->isEmpty()) {
            return [];
        }

        $labEntries = LaboratoryOrderModel::query()
            ->where('ordered_by_user_id', $clinicianUserId)
            ->where('status', LaboratoryOrderStatus::COMPLETED->value)
            ->whereIn('appointment_id', $activeAppointmentIds)
            ->get(['id', 'patient_id', 'appointment_id', 'test_name', 'resulted_at'])
            ->map(fn (LaboratoryOrderModel $order): array => [
                'orderType' => 'laboratory',
                'orderId' => $order->id,
                'patientId' => $order->patient_id,
                'appointmentId' => $order->appointment_id,
                'label' => $order->test_name,
                'completedAt' => $order->resulted_at,
            ]);

        $pharmacyEntries = PharmacyOrderModel::query()
            ->where('ordered_by_user_id', $clinicianUserId)
            ->where('status', PharmacyOrderStatus::DISPENSED->value)
            ->whereIn('appointment_id', $activeAppointmentIds)
            ->get(['id', 'patient_id', 'appointment_id', 'medication_name', 'dispensed_at'])
            ->map(fn (PharmacyOrderModel $order): array => [
                'orderType' => 'pharmacy',
                'orderId' => $order->id,
                'patientId' => $order->patient_id,
                'appointmentId' => $order->appointment_id,
                'label' => $order->medication_name,
                'completedAt' => $order->dispensed_at,
            ]);

        $radiologyEntries = RadiologyOrderModel::query()
            ->where('ordered_by_user_id', $clinicianUserId)
            ->where('status', RadiologyOrderStatus::COMPLETED->value)
            ->whereIn('appointment_id', $activeAppointmentIds)
            ->get(['id', 'patient_id', 'appointment_id', 'study_description', 'completed_at'])
            ->map(fn (RadiologyOrderModel $order): array => [
                'orderType' => 'radiology',
                'orderId' => $order->id,
                'patientId' => $order->patient_id,
                'appointmentId' => $order->appointment_id,
                'label' => $order->study_description,
                'completedAt' => $order->completed_at,
            ]);

        $clinicalProcedureEntries = ClinicalProcedureOrderModel::query()
            ->where('ordered_by_user_id', $clinicianUserId)
            ->where('status', ClinicalProcedureOrderStatus::COMPLETED->value)
            ->whereIn('appointment_id', $activeAppointmentIds)
            ->get(['id', 'patient_id', 'appointment_id', 'procedure_description', 'completed_at'])
            ->map(fn (ClinicalProcedureOrderModel $order): array => [
                'orderType' => 'clinical_procedure',
                'orderId' => $order->id,
                'patientId' => $order->patient_id,
                'appointmentId' => $order->appointment_id,
                'label' => $order->procedure_description,
                'completedAt' => $order->completed_at,
            ]);

        /** @var Collection<int, array<string, mixed>> $entries */
        $entries = $labEntries->concat($pharmacyEntries)->concat($radiologyEntries)->concat($clinicalProcedureEntries);

        if ($entries->isEmpty()) {
            return [];
        }

        $patientsById = PatientModel::query()
            ->whereIn('id', $entries->pluck('patientId')->unique())
            ->get(['id', 'patient_number', 'first_name', 'middle_name', 'last_name'])
            ->keyBy('id');

        return $entries
            ->map(function (array $entry) use ($patientsById): array {
                $patient = $patientsById->get($entry['patientId']);
                $patientName = $patient !== null
                    ? implode(' ', array_filter([
                        $patient->first_name,
                        $patient->middle_name,
                        $patient->last_name,
                    ], static fn (?string $part): bool => $part !== null && trim($part) !== ''))
                    : null;

                $entry['patientName'] = $patientName !== '' ? $patientName : null;
                $entry['patientNumber'] = $patient?->patient_number;

                return $entry;
            })
            ->sortByDesc(fn (array $entry) => $entry['completedAt']?->timestamp ?? 0)
            ->values()
            ->all();
    }
}
