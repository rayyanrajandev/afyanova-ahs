<?php

namespace App\Modules\PatientFlow\Application\UseCases;

use App\Modules\Laboratory\Domain\ValueObjects\LaboratoryOrderStatus;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\ClinicalProcedure\Domain\ValueObjects\ClinicalProcedureOrderStatus;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;

/**
 * Extracted from GetActiveVisitJourneyUseCase::deriveAppointmentStep()'s
 * IN_CONSULTATION branch — the only part of that derivation that needs
 * batched Laboratory/Pharmacy/Radiology order lookups, and the only part a
 * second consumer (GetReceptionQueueUseCase, for clinician/Queue.vue's
 * "In progress · In lab" indicator) actually needs. GetActiveVisitJourneyUseCase
 * still owns the WAITING_TRIAGE/WAITING_PROVIDER branches (triage-claim and
 * consultation_started_at signals) — those don't touch diagnostic orders at
 * all, so there was nothing to share there.
 *
 * Pure extraction, not a behavior change: the same three batched queries and
 * the same earliest-incomplete-step-wins precedence
 * (waiting > in-progress > pharmacy > with_clinician) that
 * GetActiveVisitJourneyUseCase always used.
 */
class ResolveConsultationDiagnosticStepsUseCase
{
    private const LAB_WAITING_STATUSES = [LaboratoryOrderStatus::ORDERED->value];

    private const LAB_IN_PROGRESS_STATUSES = [
        LaboratoryOrderStatus::COLLECTED->value,
        LaboratoryOrderStatus::IN_PROGRESS->value,
    ];

    private const RADIOLOGY_WAITING_STATUSES = [
        RadiologyOrderStatus::ORDERED->value,
        RadiologyOrderStatus::SCHEDULED->value,
    ];

    private const RADIOLOGY_IN_PROGRESS_STATUSES = [RadiologyOrderStatus::IN_PROGRESS->value];

    /**
     * @param  array<int, string>  $appointmentIds  Only appointments actually
     *   in_consultation need to be passed — the result for any other
     *   appointment id is meaningless (it will just resolve to
     *   'with_clinician' for lack of any open order, not an error).
     * @return array<string, array{step: string, stepEnteredAt: string|null, openOrders: array<int, array{type: string, label: string}>}> appointmentId => step
     *   ('waiting_lab' | 'waiting_imaging' | 'waiting_lab_and_imaging' | 'in_lab' | 'in_imaging' | 'in_lab_and_imaging' | 'waiting_pharmacy' | 'with_clinician')
     *   plus the earliest relevant open order's ordered_at, for the
     *   elapsed-time badge on patient-flow/Board.vue, and every open order's
     *   {type, label} for the board's outstanding-orders list. Laboratory orders have
     *   no per-transition (e.g. "collection started") timestamp at all, so
     *   in_lab/in_imaging/in_lab_and_imaging deliberately reuse the same
     *   ordered_at rather than resetting the clock at a transition only
     *   Radiology can actually mark — "how long this diagnostic work has
     *   been outstanding," not "how long since the current sub-status."
     */
    public function resolveForAppointmentIds(array $appointmentIds): array
    {
        if ($appointmentIds === []) {
            return [];
        }

        $labRowsByAppointmentId = LaboratoryOrderModel::query()
            ->whereIn('appointment_id', $appointmentIds)
            ->whereIn('status', LaboratoryOrderStatus::openWorklistValues())
            ->get(['appointment_id', 'status', 'ordered_at', 'test_name'])
            ->groupBy('appointment_id');

        $radiologyRowsByAppointmentId = RadiologyOrderModel::query()
            ->whereIn('appointment_id', $appointmentIds)
            ->whereIn('status', RadiologyOrderStatus::openWorklistValues())
            ->get(['appointment_id', 'status', 'ordered_at', 'study_description'])
            ->groupBy('appointment_id');

        $pharmacyRowsByAppointmentId = PharmacyOrderModel::query()
            ->whereIn('appointment_id', $appointmentIds)
            ->whereIn('status', PharmacyOrderStatus::openWorklistValues())
            ->get(['appointment_id', 'ordered_at', 'medication_name'])
            ->groupBy('appointment_id');

        $clinicalProcedureRowsByAppointmentId = ClinicalProcedureOrderModel::query()
            ->whereIn('appointment_id', $appointmentIds)
            ->whereIn('status', ClinicalProcedureOrderStatus::openWorklistValues())
            ->get(['appointment_id', 'ordered_at', 'procedure_description'])
            ->groupBy('appointment_id');

        $result = [];
        foreach ($appointmentIds as $appointmentId) {
            $labRows = $labRowsByAppointmentId->get($appointmentId, collect());
            $radiologyRows = $radiologyRowsByAppointmentId->get($appointmentId, collect());
            $pharmacyRows = $pharmacyRowsByAppointmentId->get($appointmentId, collect());
            $clinicalProcedureRows = $clinicalProcedureRowsByAppointmentId->get($appointmentId, collect());

            $step = $this->deriveStep(
                $labRows->pluck('status')->all(),
                $radiologyRows->pluck('status')->all(),
                $pharmacyRows->isNotEmpty(),
            );

            $result[$appointmentId] = [
                'step' => $step,
                'stepEnteredAt' => $this->stepEnteredAt($step, $labRows, $radiologyRows, $pharmacyRows),
                'openOrders' => $this->openOrders($labRows, $radiologyRows, $pharmacyRows, $clinicalProcedureRows),
            ];
        }

        return $result;
    }

    /**
     * Every open order across all three types, not just the one determining
     * the current step — a patient with both a pending CBC and a pending
     * X-ray should show both on their card, even though the step name only
     * reflects one precedence-ranked value (board display detail, not a
     * change to deriveStep()'s own precedence).
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $labRows
     * @param  \Illuminate\Support\Collection<int, mixed>  $radiologyRows
     * @param  \Illuminate\Support\Collection<int, mixed>  $pharmacyRows
     * @param  \Illuminate\Support\Collection<int, mixed>  $clinicalProcedureRows
     * @return array<int, array{type: string, label: string}>
     */
    private function openOrders($labRows, $radiologyRows, $pharmacyRows, $clinicalProcedureRows): array
    {
        $labOrders = $labRows->map(fn ($row) => ['type' => 'lab', 'label' => $row->test_name ?? 'Laboratory test']);
        $radiologyOrders = $radiologyRows->map(fn ($row) => ['type' => 'imaging', 'label' => $row->study_description ?? 'Imaging study']);
        $pharmacyOrders = $pharmacyRows->map(fn ($row) => ['type' => 'pharmacy', 'label' => $row->medication_name ?? 'Medication']);
        $clinicalProcedureOrders = $clinicalProcedureRows->map(fn ($row) => ['type' => 'clinical_procedure', 'label' => $row->procedure_description ?? 'Clinical procedure']);

        return $labOrders->concat($radiologyOrders)->concat($pharmacyOrders)->concat($clinicalProcedureOrders)->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $labRows
     * @param  \Illuminate\Support\Collection<int, mixed>  $radiologyRows
     * @param  \Illuminate\Support\Collection<int, mixed>  $pharmacyRows
     */
    private function stepEnteredAt(string $step, $labRows, $radiologyRows, $pharmacyRows): ?string
    {
        return match (true) {
            $step === 'waiting_pharmacy' => $this->earliestOrderedAt($pharmacyRows),
            str_contains($step, 'lab') && str_contains($step, 'imaging') => $this->earliestOrderedAt($labRows->concat($radiologyRows)),
            str_contains($step, 'lab') => $this->earliestOrderedAt($labRows),
            str_contains($step, 'imaging') => $this->earliestOrderedAt($radiologyRows),
            default => null,
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $rows
     */
    private function earliestOrderedAt($rows): ?string
    {
        $earliest = $rows->pluck('ordered_at')->filter()->min();

        return $earliest?->toISOString();
    }

    /**
     * @param  array<int, string>  $openLabStatuses
     * @param  array<int, string>  $openRadiologyStatuses
     */
    private function deriveStep(array $openLabStatuses, array $openRadiologyStatuses, bool $hasOpenPharmacy): string
    {
        // Earliest-incomplete-step wins — a diagnostic order that hasn't
        // started yet is a more useful signal than one already in progress,
        // since it is the one nobody has acted on at all yet.
        //
        // Lab and radiology are physically different departments, so a queue
        // viewer needs to know which one the patient is actually at — a
        // combined "waiting_lab" for both was ambiguous and, for a
        // radiology-only order, outright wrong (see git history: it labeled
        // "scheduled for X-ray" as "waiting on lab"). Both can be open at
        // once, so that combination gets its own explicit value rather than
        // silently picking one.
        $labWaiting = array_intersect($openLabStatuses, self::LAB_WAITING_STATUSES) !== [];
        $radiologyWaiting = array_intersect($openRadiologyStatuses, self::RADIOLOGY_WAITING_STATUSES) !== [];
        if ($labWaiting && $radiologyWaiting) {
            return 'waiting_lab_and_imaging';
        }
        if ($labWaiting) {
            return 'waiting_lab';
        }
        if ($radiologyWaiting) {
            return 'waiting_imaging';
        }

        $labInProgress = array_intersect($openLabStatuses, self::LAB_IN_PROGRESS_STATUSES) !== [];
        $radiologyInProgress = array_intersect($openRadiologyStatuses, self::RADIOLOGY_IN_PROGRESS_STATUSES) !== [];
        if ($labInProgress && $radiologyInProgress) {
            return 'in_lab_and_imaging';
        }
        if ($labInProgress) {
            return 'in_lab';
        }
        if ($radiologyInProgress) {
            return 'in_imaging';
        }

        if ($hasOpenPharmacy) {
            return 'waiting_pharmacy';
        }

        return 'with_clinician';
    }
}
