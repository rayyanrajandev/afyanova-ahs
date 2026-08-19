<?php

namespace App\Modules\PatientFlow\Application\UseCases;

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientAllergyModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\PatientFlow\Domain\Repositories\PatientFlowEventRepositoryInterface;
use App\Modules\Revenue\Domain\Services\OutstandingBalanceReaderInterface;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestStatus;
use App\Modules\ServiceRequest\Infrastructure\Models\ServiceRequestModel;
use Illuminate\Support\Collection;

/**
 * Phase 1 of reports/queue-based-workflow-modernization-plan.md §3.2: a live
 * query, not a persisted table — the same "nothing computed here can drift
 * because nothing here is a second copy" reasoning already validated for
 * GetReceptionQueueUseCase (avoids the C-7 shape). Derives one current-step
 * value per active visit from AppointmentStatus plus open orders across
 * Laboratory/Pharmacy/Radiology, closing the gap
 * reports/queue-based-workflow-audit.md §2-3 documented: those three modules'
 * order statuses exist but are invisible to the visit's own queue position,
 * and nothing connects a completed order back to "the visit can move on."
 *
 * One known limit, intentional for this phase, not an oversight:
 *
 * - "Waiting for Clinician Review" is inferred, not stored: a WAITING_PROVIDER
 *   appointment whose consultation_started_at is already set has been through
 *   at least one consultation before (per
 *   AppointmentController::updateProviderWorkflow()'s in_consultation ->
 *   waiting_provider release path) — this is the only real signal available
 *   to distinguish "returning after being sent for orders" from "waiting for
 *   the first consultation," short of adding a new persisted field, which
 *   this read-only phase deliberately does not do.
 *
 * "In Triage" is distinguished from "Waiting for Triage" using Phase 2's
 * triage_owner_user_id claim (set by ClaimAppointmentTriageUseCase) — a
 * WAITING_TRIAGE appointment with a claim in place is 'in_triage', otherwise
 * 'waiting_triage'.
 *
 * The diagnostic-order derivation
 * (waiting_lab/in_lab/waiting_pharmacy/with_clinician) is delegated to
 * ResolveConsultationDiagnosticStepsUseCase, and consulted from both the
 * IN_CONSULTATION and WAITING_PROVIDER branches — extracted so
 * GetReceptionQueueUseCase (clinician/Queue.vue's "In progress · In lab"
 * indicator) can reuse the exact same batched Laboratory/Pharmacy/Radiology
 * lookups and precedence rules without a second, potentially-drifting copy.
 * Pure extraction, not a behavior change to this use case.
 *
 * Phase 1b: direct-service walk-ins (patients/Index.vue's "Direct services"
 * handoff mode, POST /service-requests) bypass triage/consultation entirely
 * for a patient who needs only a lab/pharmacy/radiology/theatre service, not
 * a doctor visit — often with no appointment_id at all. Every open
 * (PENDING/IN_PROGRESS), not-yet-linked-to-a-real-order ServiceRequest
 * becomes its own entry here (appointmentId nullable, serviceRequestId set),
 * deriving waiting_direct_service/in_direct_service — new, distinct steps,
 * not folded into waiting_lab/in_lab, since this patient never saw a
 * clinician at all. A request with linked_order_id already set is excluded:
 * once linked, the real order (already covered above) is what matters, and
 * counting both would double the same underlying work on the board.
 *
 * Elapsed-time-indicator pass (queue-ecosystem-epic-cerner-oracle-comparison-audit.md):
 * every entry also carries `stepEnteredAt`, the timestamp the patient
 * entered their *current* step — sourced per-step from whatever column
 * actually marks that transition (checked_in_at, triage_owner_assigned_at,
 * consultation_started_at, the earliest open order's ordered_at, or a
 * ServiceRequest's requested_at/acknowledged_at). `waiting_clinician` and
 * `waiting_clinician_review` have no such column at all — they were
 * deliberately `null` rather than approximated from an unrelated timestamp,
 * and are now filled in from patient_flow_events, which records the exact
 * moment (see fillMissingStepEnteredAtFromFlowLog()). Still null when the log
 * has no event for that visit, which is the honest answer.
 *
 * `with_nurse` (2026-08-16) is derived from nursing_contact_user_id, the third
 * use of this codebase's ownership-column shape after consultation and triage
 * ownership. Read from that column and not from the flow log on purpose: the
 * log is best-effort by design (RecordPatientFlowTransitionService swallows
 * failures), and a board that depends on a source allowed to miss writes would
 * eventually drop a patient off a queue.
 */
class GetActiveVisitJourneyUseCase
{
    public function __construct(
        private readonly ResolveConsultationDiagnosticStepsUseCase $consultationStepResolver,
        private readonly PatientFlowEventRepositoryInterface $flowEventRepository,
        private readonly OutstandingBalanceReaderInterface $outstandingBalanceReader,
    ) {}

    /**
     * Public so Phase 4's GetOrderCompletionNotificationsForClinicianUseCase
     * can reuse the exact same "what counts as an active visit" definition
     * rather than redefining it — same reasoning as C-8's promotion of the
     * lab/pharmacy/radiology terminal-status consts in
     * GetEncounterCloseReadinessUseCase.
     */
    public const ACTIVE_APPOINTMENT_STATUSES = [
        AppointmentStatus::WAITING_TRIAGE->value,
        AppointmentStatus::WAITING_PROVIDER->value,
        AppointmentStatus::IN_CONSULTATION->value,
    ];

    private const OPEN_SERVICE_REQUEST_STATUSES = [
        ServiceRequestStatus::PENDING->value,
        ServiceRequestStatus::IN_PROGRESS->value,
    ];

    private const SERVICE_TYPE_LABELS = [
        'laboratory' => 'Laboratory',
        'pharmacy' => 'Pharmacy',
        'radiology' => 'Radiology',
        'clinical_procedure' => 'Clinical procedure',
        'theatre_procedure' => 'Theatre procedure',
    ];

    /**
     * @param  string|null  $patientId  Scopes the board to one patient — pushed into the
     *   underlying queries themselves (not fetch-the-whole-board-then-filter), so
     *   GetPatientSummaryUseCase can ask "does this one patient have an active
     *   visit right now?" without materializing the entire facility board.
     * @param  string|null  $department  Board filter — matched against AppointmentModel.department
     *   directly, and against the ServiceRequest's display label (SERVICE_TYPE_LABELS) for
     *   direct-service entries, which have no literal "department" column.
     * @param  int|null  $clinicianUserId  Board filter — appointments only; service-request
     *   entries have no clinician at all, so they're naturally excluded when this is set.
     * @param  string|null  $q  Board filter — patient name/number search, applied in-memory
     *   against the already-batched patient lookup rather than a cross-table query: this
     *   dataset (every currently-active visit in a facility) is small and unpaginated today,
     *   so a proper indexed join would be more invasive for no real benefit at this scale.
     * @return array<int, array<string, mixed>>
     */
    public function execute(
        ?string $patientId = null,
        ?string $department = null,
        ?int $clinicianUserId = null,
        ?string $q = null,
    ): array {
        $appointments = AppointmentModel::query()
            ->whereIn('status', self::ACTIVE_APPOINTMENT_STATUSES)
            ->when($patientId !== null, fn ($query) => $query->where('patient_id', $patientId))
            ->when($department !== null, fn ($query) => $query->where('department', $department))
            ->when($clinicianUserId !== null, fn ($query) => $query->where('clinician_user_id', $clinicianUserId))
            ->get();

        // Service requests have no clinician at all — a clinician filter
        // naturally excludes every one of them rather than matching none, so
        // skip the query entirely instead of fetching rows only to discard them.
        $serviceRequests = $clinicianUserId !== null
            ? collect()
            : ServiceRequestModel::query()
                ->whereIn('status', self::OPEN_SERVICE_REQUEST_STATUSES)
                ->whereNull('linked_order_id')
                ->when($patientId !== null, fn ($query) => $query->where('patient_id', $patientId))
                ->get()
                ->when(
                    $department !== null,
                    fn (Collection $requests) => $requests->filter(
                        fn (ServiceRequestModel $request) => (self::SERVICE_TYPE_LABELS[$request->service_type] ?? $request->service_type) === $department,
                    ),
                );

        if ($appointments->isEmpty() && $serviceRequests->isEmpty()) {
            return [];
        }

        $appointmentIds = $appointments->pluck('id')->all();

        $consultationStepsByAppointmentId = $this->consultationStepResolver->resolveForAppointmentIds($appointmentIds);

        // Batched, not per-row — same reasoning as GetReceptionQueueUseCase:
        // a board showing only patientId is not usable by the staff it's for.
        $patientIds = $appointments->pluck('patient_id')
            ->concat($serviceRequests->pluck('patient_id'))
            ->unique();
        $patientsById = PatientModel::query()
            ->whereIn('id', $patientIds)
            ->get(['id', 'patient_number', 'first_name', 'middle_name', 'last_name'])
            ->keyBy('id');

        if ($q !== null && trim($q) !== '') {
            $patientIds = $this->filterPatientIdsByQuery($patientsById, $patientIds, $q);
        }

        $allergiesByPatientId = PatientAllergyModel::query()
            ->whereIn('patient_id', $patientIds)
            ->where('status', 'active')
            ->get(['patient_id', 'substance_name', 'severity'])
            ->groupBy('patient_id');

        $patientsWithOutstanding = $this->outstandingBalanceReader->patientsWithOutstanding(
            $patientIds->map(static fn (mixed $id): string => (string) $id)->values()->all(),
        );

        $appointmentEntries = $appointments
            ->filter(fn (AppointmentModel $appointment) => $patientIds->contains($appointment->patient_id))
            ->map(function (AppointmentModel $appointment) use (
                $consultationStepsByAppointmentId,
                $patientsById,
                $allergiesByPatientId,
                $patientsWithOutstanding,
            ): array {
                $consultationStep = $consultationStepsByAppointmentId[$appointment->id] ?? null;
                [$step, $stepEnteredAt] = $this->deriveAppointmentStep($appointment, $consultationStep);

                return [
                    'appointmentId' => $appointment->id,
                    'serviceRequestId' => null,
                    'patientId' => $appointment->patient_id,
                    'patientName' => $this->patientName($patientsById, $appointment->patient_id),
                    'patientNumber' => $patientsById->get($appointment->patient_id)?->patient_number,
                    'department' => $appointment->department,
                    /**
                     * consultation_owner_user_id (current owner, updated on every
                     * takeover) takes priority over clinician_user_id (the
                     * originally scheduled clinician) — falls back to it only
                     * before a consultation has actually started, when no owner
                     * has been assigned yet. Showing clinician_user_id alone
                     * after a takeover would display the replaced doctor, not
                     * who's actually seeing the patient.
                     */
                    'clinicianUserId' => $appointment->consultation_owner_user_id ?? $appointment->clinician_user_id,
                    'consultationTakeoverCount' => (int) ($appointment->consultation_takeover_count ?? 0),
                    'appointmentStatus' => $appointment->status,
                    'step' => $step,
                    'stepEnteredAt' => $stepEnteredAt,
                    'priority' => $appointment->triage_category,
                    'openOrders' => $consultationStep['openOrders'] ?? [],
                    'allergies' => $this->allergiesFor($allergiesByPatientId, $appointment->patient_id),
                    'billingStatus' => ($patientsWithOutstanding[$appointment->patient_id] ?? false) ? 'pending' : null,
                ];
            });

        $serviceRequestEntries = $serviceRequests
            ->filter(fn (ServiceRequestModel $serviceRequest) => $patientIds->contains($serviceRequest->patient_id))
            ->map(function (ServiceRequestModel $serviceRequest) use (
                $patientsById,
                $allergiesByPatientId,
                $patientsWithOutstanding,
            ): array {
                $isInProgress = $serviceRequest->status === ServiceRequestStatus::IN_PROGRESS->value;

                return [
                    'appointmentId' => $serviceRequest->appointment_id,
                    'serviceRequestId' => $serviceRequest->id,
                    'patientId' => $serviceRequest->patient_id,
                    'patientName' => $this->patientName($patientsById, $serviceRequest->patient_id),
                    'patientNumber' => $patientsById->get($serviceRequest->patient_id)?->patient_number,
                    'department' => self::SERVICE_TYPE_LABELS[$serviceRequest->service_type] ?? $serviceRequest->service_type,
                    'clinicianUserId' => null,
                    'consultationTakeoverCount' => 0,
                    'appointmentStatus' => null,
                    'step' => $isInProgress ? 'in_direct_service' : 'waiting_direct_service',
                    // acknowledged_at is set precisely on the PENDING -> IN_PROGRESS
                    // transition (UpdateServiceRequestStatusUseCase), so it's a real
                    // "entered this step" marker, not a proxy.
                    'stepEnteredAt' => $isInProgress
                        ? optional($serviceRequest->acknowledged_at)->toISOString()
                        : optional($serviceRequest->requested_at)->toISOString(),
                    // Direct-service walk-ins never go through triage — there's no
                    // priority signal available for them.
                    'priority' => null,
                    'openOrders' => [],
                    'allergies' => $this->allergiesFor($allergiesByPatientId, $serviceRequest->patient_id),
                    'billingStatus' => ($patientsWithOutstanding[$serviceRequest->patient_id] ?? false) ? 'pending' : null,
                ];
            });

        return $this->fillMissingStepEnteredAtFromFlowLog(
            $appointmentEntries->concat($serviceRequestEntries)->values()->all(),
        );
    }

    /**
     * Fills in `stepEnteredAt` for the steps no column can answer.
     *
     * waiting_clinician and waiting_clinician_review have no timestamp column of
     * their own — nothing marks "triage finished" or "released back to the queue"
     * distinctly from consultation_started_at — so this use case has always
     * returned null for them rather than approximating from an unrelated column.
     * patient_flow_events now records the exact moment, so those two are read
     * back from the log here.
     *
     * Enrichment, never authority: the step itself is still derived from the
     * transactional status/ownership columns above, and an entry whose event is
     * missing (the log is best-effort, and visits that predate it have no
     * history at all) simply keeps its null — the elapsed-time indicator hides,
     * exactly as it did before. Nothing disappears from the board.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function fillMissingStepEnteredAtFromFlowLog(array $entries): array
    {
        $appointmentIdsNeedingTimestamp = [];
        foreach ($entries as $entry) {
            if ($entry['stepEnteredAt'] === null && $entry['appointmentId'] !== null) {
                $appointmentIdsNeedingTimestamp[] = (string) $entry['appointmentId'];
            }
        }

        if ($appointmentIdsNeedingTimestamp === []) {
            return $entries;
        }

        $enteredAtByAppointmentId = $this->flowEventRepository
            ->currentStepEnteredAtByAppointmentIds(array_values(array_unique($appointmentIdsNeedingTimestamp)));

        if ($enteredAtByAppointmentId === []) {
            return $entries;
        }

        foreach ($entries as $index => $entry) {
            if ($entry['stepEnteredAt'] !== null || $entry['appointmentId'] === null) {
                continue;
            }

            $entries[$index]['stepEnteredAt'] = $enteredAtByAppointmentId[(string) $entry['appointmentId']] ?? null;
        }

        return $entries;
    }

    /**
     * @param  Collection<string, PatientModel>  $patientsById
     * @param  Collection<int, string>  $patientIds
     * @return Collection<int, string>
     */
    private function filterPatientIdsByQuery(Collection $patientsById, Collection $patientIds, string $q): Collection
    {
        $needle = mb_strtolower(trim($q));

        return $patientIds->filter(function (string $patientId) use ($patientsById, $needle): bool {
            $patient = $patientsById->get($patientId);
            if ($patient === null) {
                return false;
            }

            $haystack = mb_strtolower(implode(' ', array_filter([
                $patient->first_name,
                $patient->middle_name,
                $patient->last_name,
                $patient->patient_number,
            ])));

            return str_contains($haystack, $needle);
        })->values();
    }

    /**
     * @param  Collection<string, Collection<int, mixed>>  $allergiesByPatientId
     * @return array<int, array{substanceName: string, severity: string}>
     */
    private function allergiesFor(Collection $allergiesByPatientId, ?string $patientId): array
    {
        if ($patientId === null) {
            return [];
        }

        return $allergiesByPatientId->get($patientId, collect())
            ->map(fn ($row) => ['substanceName' => $row->substance_name, 'severity' => $row->severity])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, PatientModel>  $patientsById
     */
    private function patientName(Collection $patientsById, ?string $patientId): ?string
    {
        $patient = $patientId !== null ? $patientsById->get($patientId) : null;
        if ($patient === null) {
            return null;
        }

        $name = implode(' ', array_filter([
            $patient->first_name,
            $patient->middle_name,
            $patient->last_name,
        ], static fn (?string $part): bool => $part !== null && trim($part) !== ''));

        return $name !== '' ? $name : null;
    }

    /**
     * $consultationStep is the pre-resolved diagnostic step + stepEnteredAt
     * (ResolveConsultationDiagnosticStepsUseCase). Used by both the
     * IN_CONSULTATION and WAITING_PROVIDER branches — a visit sent out for
     * labs sits in WAITING_PROVIDER while the order is open, so open orders
     * decide the step in that branch too. WAITING_TRIAGE ignores it; nothing
     * has been ordered yet at that point.
     *
     * @param  array{step: string, stepEnteredAt: string|null}|null  $consultationStep
     * @return array{0: string, 1: string|null} [step, stepEnteredAt]
     *
     * stepEnteredAt is null for waiting_clinician/waiting_clinician_review —
     * no column marks "triage finished" or "released back to the queue"
     * distinct from consultation_started_at, so this is honestly absent
     * rather than approximated from an unrelated timestamp.
     */
    private function deriveAppointmentStep(AppointmentModel $appointment, ?array $consultationStep): array
    {
        // Nursing pickup outranks the queue the patient is waiting in (but never
        // an active consultation — see PatientFlowStep::fromAppointmentStatus()'s
        // precedence note). Read from the transactional column, not the
        // best-effort flow log.
        if (
            $appointment->nursing_contact_user_id !== null
            && $appointment->status !== AppointmentStatus::IN_CONSULTATION->value
        ) {
            return ['with_nurse', optional($appointment->nursing_contact_started_at)->toISOString()];
        }

        if ($appointment->status === AppointmentStatus::WAITING_TRIAGE->value) {
            if ($appointment->triage_owner_user_id !== null) {
                return ['in_triage', optional($appointment->triage_owner_assigned_at)->toISOString()];
            }

            return ['waiting_triage', optional($appointment->checked_in_at)->toISOString()];
        }

        if ($appointment->status === AppointmentStatus::WAITING_PROVIDER->value) {
            /**
             * A visit waiting for a provider may be waiting *at a department*
             * rather than in the provider queue.
             *
             * updateProviderWorkflow() deliberately returns a visit to
             * WAITING_PROVIDER when the doctor sends it out for diagnostics — it
             * preserves consultation_started_at for exactly that, commented
             * "sent out for labs, will return". But the diagnostic step was only
             * consulted in the IN_CONSULTATION branch below, so for the whole
             * time the patient was physically in the lab this read "Waiting for
             * Doctor Review" (2026-08-16 laboratory flow plan, phase 1).
             *
             * The resolver returns 'with_clinician' when nothing is outstanding,
             * so that value means "no department holds this patient" and falls
             * through to the provider-queue answer below — which is also a truer
             * reading of waiting_clinician_review: the results really are back.
             */
            $diagnosticStep = $consultationStep['step'] ?? null;

            if ($diagnosticStep !== null && $diagnosticStep !== 'with_clinician') {
                return [$diagnosticStep, $consultationStep['stepEnteredAt'] ?? null];
            }

            // stepEnteredAt stays null here — no column marks "triage finished"
            // or "released back to the queue" distinctly from
            // consultation_started_at. execute() fills these two steps in from
            // the flow log afterwards, which does record the exact moment; see
            // the enrichment pass there.
            if ($appointment->consultation_started_at !== null) {
                return ['waiting_clinician_review', null];
            }

            return ['waiting_clinician', null];
        }

        // IN_CONSULTATION: the resolver's stepEnteredAt only covers the
        // diagnostic-order branches (waiting_lab/in_lab/etc.) — 'with_clinician'
        // itself (whether resolved that way or defaulted to it) always uses
        // consultation_started_at, the actually-correct source for that step.
        $step = $consultationStep['step'] ?? 'with_clinician';
        $stepEnteredAt = $step === 'with_clinician'
            ? optional($appointment->consultation_started_at)->toISOString()
            : ($consultationStep['stepEnteredAt'] ?? null);

        return [$step, $stepEnteredAt];
    }
}
