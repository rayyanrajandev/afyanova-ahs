<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Encounter\Application\UseCases\GetEncounterCloseReadinessUseCase;
use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Encounter\Application\Services\PrimaryMedicalRecordResolverService;
use App\Modules\Encounter\Infrastructure\Models\EncounterDiagnosisModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\MedicalRecord\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use App\Support\ClinicalOrders\ClinicalOrderEntryState;
use Illuminate\Database\Eloquent\Builder;

class GetEncounterWorkspaceUseCase
{
    private const CARE_ARTIFACT_LIMIT = 6;

    public function __construct(
        private readonly EncounterResolverService $encounterResolverService,
        private readonly PrimaryMedicalRecordResolverService $primaryMedicalRecordResolverService,
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly GetEncounterCloseReadinessUseCase $encounterCloseReadinessUseCase,
        private readonly PatientRepositoryInterface $patientRepository,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $encounterId): ?array
    {
        $encounter = $this->encounterResolverService->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        $encounterArray = $encounter->toArray();
        $patientId = trim((string) ($encounterArray['patient_id'] ?? ''));

        $appointment = null;
        $appointmentId = trim((string) ($encounterArray['appointment_id'] ?? ''));
        if ($appointmentId !== '') {
            $appointment = $this->appointmentLookupService->findById($appointmentId);
        }

        $admission = null;
        $admissionId = trim((string) ($encounterArray['admission_id'] ?? ''));
        if ($admissionId !== '') {
            $admission = AdmissionModel::query()->find($admissionId)?->toArray();
        }

        return [
            'encounter' => $encounterArray,
            'patient' => $patientId !== '' ? $this->patientRepository->findById($patientId) : null,
            'primaryMedicalRecord' => $this->primaryMedicalRecordResolverService->resolve($encounterId, $patientId),
            'appointment' => $appointment,
            'admission' => $admission,
            'diagnoses' => $this->loadDiagnoses($encounterId),
            'laboratoryOrders' => $this->loadLaboratoryOrders($encounterId),
            'laboratoryOrdersPendingCount' => $this->countPendingLaboratoryOrders($encounterId),
            'pharmacyOrders' => $this->loadPharmacyOrders($encounterId),
            'pharmacyOrdersPendingCount' => $this->countPendingPharmacyOrders($encounterId),
            'radiologyOrders' => $this->loadRadiologyOrders($encounterId),
            'radiologyOrdersPendingCount' => $this->countPendingRadiologyOrders($encounterId),
            'theatreProcedures' => $this->loadTheatreProcedures($encounterId),
            'theatreProceduresPendingCount' => $this->countPendingTheatreProcedures($encounterId),
            'clinicalProcedureOrders' => $this->loadClinicalProcedureOrders($encounterId),
            'clinicalProcedureOrdersPendingCount' => $this->countPendingClinicalProcedureOrders($encounterId),
            'closeReadiness' => $this->encounterCloseReadinessUseCase->execute($encounterId),
            'canEdit' => $this->resolveCanEdit($encounterArray, $appointment),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadDiagnoses(string $encounterId): array
    {
        return EncounterDiagnosisModel::query()
            ->where('encounter_id', $encounterId)
            ->with('recordedBy:id,name')
            ->orderByRaw("CASE WHEN diagnosis_type = 'primary' THEN 0 ELSE 1 END")
            ->orderByDesc('recorded_at')
            ->get()
            ->map(static fn (EncounterDiagnosisModel $diagnosis): array => $diagnosis->toArray())
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadLaboratoryOrders(string $encounterId): array
    {
        $query = LaboratoryOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value);
        $this->orderPendingFirst($query, GetEncounterCloseReadinessUseCase::labTerminalStatuses());

        return $query
            ->orderByDesc('ordered_at')
            ->orderByDesc('created_at')
            ->limit(self::CARE_ARTIFACT_LIMIT)
            ->get()
            ->map(static fn (LaboratoryOrderModel $order): array => $order->toArray())
            ->all();
    }

    private function countPendingLaboratoryOrders(string $encounterId): int
    {
        return LaboratoryOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->whereNotIn('status', GetEncounterCloseReadinessUseCase::labTerminalStatuses())
            ->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadPharmacyOrders(string $encounterId): array
    {
        $query = PharmacyOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value);
        $this->orderPendingFirst($query, GetEncounterCloseReadinessUseCase::pharmacyTerminalStatuses());

        return $query
            ->orderByDesc('ordered_at')
            ->orderByDesc('created_at')
            ->limit(self::CARE_ARTIFACT_LIMIT)
            ->get()
            ->map(static fn (PharmacyOrderModel $order): array => $order->toArray())
            ->all();
    }

    private function countPendingPharmacyOrders(string $encounterId): int
    {
        return PharmacyOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->whereNotIn('status', GetEncounterCloseReadinessUseCase::pharmacyTerminalStatuses())
            ->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRadiologyOrders(string $encounterId): array
    {
        $query = RadiologyOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value);
        $this->orderPendingFirst($query, GetEncounterCloseReadinessUseCase::radiologyTerminalStatuses());

        return $query
            ->orderByDesc('ordered_at')
            ->orderByDesc('created_at')
            ->limit(self::CARE_ARTIFACT_LIMIT)
            ->get()
            ->map(static fn (RadiologyOrderModel $order): array => $order->toArray())
            ->all();
    }

    private function countPendingRadiologyOrders(string $encounterId): int
    {
        return RadiologyOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->whereNotIn('status', GetEncounterCloseReadinessUseCase::radiologyTerminalStatuses())
            ->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadTheatreProcedures(string $encounterId): array
    {
        $query = TheatreProcedureModel::query()
            ->where('encounter_id', $encounterId)
            ->whereNull('entered_in_error_at');
        $this->orderPendingFirst($query, GetEncounterCloseReadinessUseCase::theatreTerminalStatuses());

        return $query
            ->orderByDesc('scheduled_at')
            ->orderByDesc('created_at')
            ->limit(self::CARE_ARTIFACT_LIMIT)
            ->get()
            ->map(static fn (TheatreProcedureModel $procedure): array => $procedure->toArray())
            ->all();
    }

    private function countPendingTheatreProcedures(string $encounterId): int
    {
        return TheatreProcedureModel::query()
            ->where('encounter_id', $encounterId)
            ->whereNull('entered_in_error_at')
            ->whereNotIn('status', GetEncounterCloseReadinessUseCase::theatreTerminalStatuses())
            ->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadClinicalProcedureOrders(string $encounterId): array
    {
        $query = ClinicalProcedureOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value);
        $this->orderPendingFirst($query, GetEncounterCloseReadinessUseCase::clinicalProcedureTerminalStatuses());

        return $query
            ->orderByDesc('ordered_at')
            ->orderByDesc('created_at')
            ->limit(self::CARE_ARTIFACT_LIMIT)
            ->get()
            ->map(static fn (ClinicalProcedureOrderModel $order): array => $order->toArray())
            ->all();
    }

    private function countPendingClinicalProcedureOrders(string $encounterId): int
    {
        return ClinicalProcedureOrderModel::query()
            ->where('encounter_id', $encounterId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->whereNotIn('status', GetEncounterCloseReadinessUseCase::clinicalProcedureTerminalStatuses())
            ->count();
    }

    /**
     * C-8 (reports/clinical-note-audit/15-critical-system-integrity-review.md):
     * each order-type panel is capped at CARE_ARTIFACT_LIMIT rows. Sorting by
     * recency alone meant an old *pending* order — exactly the one most
     * overdue for follow-up — could be silently pushed out of the visible
     * panel by newer *completed* rows, while still counting toward the
     * opaque close-readiness "N pending" badge. Sorting pending-first (using
     * the same terminal-status lists close-readiness treats as resolved, not
     * a second copy — see the constants' own docblock) means the cap can
     * only ever hide a completed/cancelled row, never a pending one, until
     * pending rows alone exceed the cap.
     *
     * @param  array<int, string>  $terminalStatuses
     */
    private function orderPendingFirst(Builder $query, array $terminalStatuses): void
    {
        $placeholders = implode(',', array_fill(0, count($terminalStatuses), '?'));
        $query->orderByRaw("CASE WHEN status IN ({$placeholders}) THEN 1 ELSE 0 END ASC", $terminalStatuses);
    }

    /**
     * Whether the current user can edit the encounter (notes, orders, diagnoses).
     * Standalone encounters (no linked appointment) are always editable.
     * Encounters linked to an appointment require the appointment to be
     * in_consultation — the clinician must start the consultation first.
     *
     * @param  array<string, mixed>  $encounter
     * @param  array<string, mixed>|null  $appointment
     */
    private function resolveCanEdit(array $encounter, ?array $appointment): bool
    {
        if ($appointment === null) {
            return true;
        }

        return ($appointment['status'] ?? '') === 'in_consultation';
    }
}
