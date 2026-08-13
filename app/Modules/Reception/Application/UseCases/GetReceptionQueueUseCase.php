<?php

namespace App\Modules\Reception\Application\UseCases;

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\PatientFlow\Application\UseCases\ResolveConsultationDiagnosticStepsUseCase;
use App\Modules\Reception\Domain\ValueObjects\ArrivalMode;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Phase 4 of reports/patient-arrival-checkin-modernization-plan.md, decided
 * scope (plan §5): a simple operational ordering — emergency arrivals first,
 * then scheduled, then walk-in, oldest-wait-first within each tier — with no
 * formal clinical acuity model required to ship it.
 *
 * Deliberately a live query, not a separately-persisted/synced
 * visit_queue_entries table as the plan's own §3.2 sketch first suggested:
 * a synced projection is exactly the two-writes-for-one-fact shape that
 * caused C-7 (reports/clinical-note-audit/15-critical-system-integrity-review.md)
 * — every appointment/arrival-event write would need to also keep a queue
 * row in sync, or the queue silently drifts from reality. Reading live means
 * there is nothing to drift. A future acuity field slots in as an additional
 * ORDER BY tier ahead of arrival-mode, not an architecture change.
 *
 * P2+P5 of the Reception/Emergency/Admission/Bed-Management audit
 * follow-through: `execute(array $filters)` now accepts `q`
 * (appointment_number + patient name/MRN), `department`,
 * `clinicianUserId`, `page`/`perPage` — mirroring ListEmergencyTriageCasesUseCase's
 * shape (use case owns clamping/validation, controller is a passthrough).
 * Deliberate exception to the SQL-pagination convention every other V2 list
 * uses: the tier/wait ordering below has no SQL representation (arrival
 * mode lives on a separate table with no FK column, resolved via a second
 * batched query), so the final sorted PHP array is paginated with
 * array_slice() rather than AppointmentModel::paginate().
 */
class GetReceptionQueueUseCase
{
    private const STAGES = [
        AppointmentStatus::WAITING_TRIAGE->value,
        AppointmentStatus::WAITING_PROVIDER->value,
        AppointmentStatus::IN_CONSULTATION->value,
    ];

    private const ARRIVAL_MODE_TIERS = [
        ArrivalMode::EMERGENCY->value => 0,
        ArrivalMode::SCHEDULED_CHECKIN->value => 1,
        ArrivalMode::WALK_IN->value => 2,
    ];

    /**
     * Arrival mode is unknown for a visit that reached this stage without
     * going through CheckInUseCase (e.g. sent back to waiting_triage from
     * in_consultation via updateProviderWorkflow, or an appointment checked
     * in before Phase 1 shipped). Defaulting to the SCHEDULED_CHECKIN tier —
     * not last — is deliberate: a queue's entire purpose is to keep every
     * waiting patient visible, so an unrecognized case must not silently
     * sink to the bottom.
     */
    private const UNKNOWN_ARRIVAL_MODE_TIER = 1;

    public function __construct(
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
        private readonly ResolveConsultationDiagnosticStepsUseCase $consultationStepResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function execute(array $filters): array
    {
        $stage = (string) ($filters['stage'] ?? '');
        if (! in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported reception queue stage: %s', $stage));
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $department = isset($filters['department']) ? trim((string) $filters['department']) : null;
        $department = $department === '' ? null : $department;

        $clinicianUserId = isset($filters['clinicianUserId']) ? trim((string) $filters['clinicianUserId']) : null;
        $clinicianUserId = $clinicianUserId === '' ? null : $clinicianUserId;

        $appointments = $this->baseQuery($stage, $query, $department, $clinicianUserId)->get();

        if ($appointments->isEmpty()) {
            return ['data' => [], 'meta' => ['currentPage' => $page, 'perPage' => $perPage, 'total' => 0, 'lastPage' => 1]];
        }

        $entries = $this->buildEntries($stage, $appointments);

        // Explicit usort, not Collection::sortBy() with multiple criteria: that
        // method's multi-comparator form expects each element to itself be a
        // two-argument (a, b) comparator, not a single-argument value
        // extractor — easy to get subtly wrong. This comparator's contract is
        // fully explicit: tier ascending (0 = emergency first) — a manual
        // reorder (Volume 3.7 T5.5) can never cross this, it's a hard floor —
        // then, within a tier, manually-positioned entries (queuePosition set,
        // ascending) before un-positioned ones, which keep the original
        // oldest-wait-first rule. An unknown wait-start is sorted to the end
        // of its tier, not treated as "waited longest" — better to be visibly
        // uncertain than to falsely claim priority.
        usort($entries, function (array $a, array $b): int {
            if ($a['tier'] !== $b['tier']) {
                return $a['tier'] <=> $b['tier'];
            }

            $aPosition = $a['queuePosition'];
            $bPosition = $b['queuePosition'];
            if ($aPosition !== null || $bPosition !== null) {
                if ($aPosition === null) {
                    return 1; // un-positioned entries sort after positioned ones
                }
                if ($bPosition === null) {
                    return -1;
                }
                if ($aPosition !== $bPosition) {
                    return $aPosition <=> $bPosition;
                }
                // Fall through to wait-time on a (theoretically impossible,
                // enforced unique per reorder write) tie.
            }

            $aTimestamp = $a['waitStartedAt']?->timestamp ?? PHP_INT_MAX;
            $bTimestamp = $b['waitStartedAt']?->timestamp ?? PHP_INT_MAX;

            return $aTimestamp <=> $bTimestamp;
        });

        $total = count($entries);
        $lastPage = max((int) ceil($total / $perPage), 1);
        $page = min($page, $lastPage);
        $paged = array_slice($entries, ($page - 1) * $perPage, $perPage);

        return [
            'data' => $paged,
            'meta' => ['currentPage' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => $lastPage],
        ];
    }

    private function baseQuery(string $stage, ?string $query, ?string $department, ?string $clinicianUserId): Builder
    {
        return AppointmentModel::query()
            ->where('status', $stage)
            // Bug fix (2026-08-12, live-reported: an emergency-registered
            // patient never appeared in this queue): this used to
            // whereNotExists() out any appointment with a matching
            // emergency_triage_cases row, on the theory that an emergency
            // arrival "is worked from /emergency/queue from that point on."
            // That page was never built — grep-confirmed zero frontend
            // consumers anywhere of GET /emergency-triage-cases — so every
            // emergency check-in was being silently hidden from the only
            // queue view that exists, with nothing else picking it up. The
            // Emergency/Scheduled/Walk-in tiering below (ARRIVAL_MODE_TIERS)
            // already existed and was already tested for exactly this case;
            // it just never had a real emergency row reach it. The
            // CreateSkeletonEmergencyTriageCase listener and its
            // emergency_triage_cases row are left in place — harmless, and
            // still feeds CreateAppointmentUseCase's own active-emergency-
            // case cross-check — this only removes the visibility exclusion.
            ->when($department, fn (Builder $builder, string $value) => $builder->where('department', $value))
            ->when($clinicianUserId, fn (Builder $builder, string $value) => $builder->where('clinician_user_id', $value))
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.strtolower($searchTerm).'%';
                $matchingPatientIds = PatientModel::query()
                    ->where(function (Builder $nested) use ($like): void {
                        $nested->whereRaw('LOWER(first_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(patient_number) LIKE ?', [$like]);
                    })
                    ->pluck('id');

                $builder->where(function (Builder $nested) use ($like, $matchingPatientIds): void {
                    $nested->whereRaw('LOWER(appointment_number) LIKE ?', [$like])
                        ->orWhereIn('patient_id', $matchingPatientIds);
                });
            });
    }

    /**
     * @param  Collection<int, AppointmentModel>  $appointments
     * @return array<int, array<string, mixed>>
     */
    private function buildEntries(string $stage, $appointments): array
    {
        $appointmentIds = $appointments->pluck('id')->all();
        $latestArrivalModeByAppointmentId = ArrivalEventModel::query()
            ->whereIn('appointment_id', $appointmentIds)
            ->orderByDesc('arrived_at')
            ->get(['appointment_id', 'arrival_mode'])
            ->unique('appointment_id')
            ->pluck('arrival_mode', 'appointment_id');

        // Batched, not per-row: a queue view showing only patientId (a UUID)
        // is not usable by the front-desk/triage staff it's for.
        $patientsById = PatientModel::query()
            ->whereIn('id', $appointments->pluck('patient_id')->unique())
            ->get(['id', 'patient_number', 'first_name', 'middle_name', 'last_name'])
            ->keyBy('id');

        // Only meaningful for in_consultation — "is the consultation note
        // already signed" answers whether documentation is done even though
        // the appointment itself hasn't been formally completed yet (see
        // reports/appointments-scheduling-workspace-modernization-plan.md's
        // "queue vs. encounter sync audit" update). Skipped for the other
        // two stages so this query never runs when it can't return anything
        // true.
        $signedNoteByAppointmentId = $stage === AppointmentStatus::IN_CONSULTATION->value
            ? $this->medicalRecordRepository->hasSignedConsultationNoteForAppointments($appointmentIds)
            : [];

        // Same reasoning as the signed-note lookup above: only in_consultation
        // rows can meaningfully be "waiting on a lab result" etc. — skipped
        // for the other two stages. Reuses GetActiveVisitJourneyUseCase's own
        // batched Laboratory/Pharmacy/Radiology lookups and precedence rules
        // (extracted into ResolveConsultationDiagnosticStepsUseCase) rather
        // than a second, potentially-drifting copy.
        $consultationStepByAppointmentId = $stage === AppointmentStatus::IN_CONSULTATION->value
            ? $this->consultationStepResolver->resolveForAppointmentIds($appointmentIds)
            : [];

        return $appointments->map(function (AppointmentModel $appointment) use (
            $stage,
            $latestArrivalModeByAppointmentId,
            $patientsById,
            $signedNoteByAppointmentId,
            $consultationStepByAppointmentId,
        ): array {
            $arrivalMode = $latestArrivalModeByAppointmentId->get($appointment->id);
            // in_consultation's "wait" is really "how long this leg of the
            // consultation has been running" — consultation_started_at, not
            // a wait-for-something timestamp. waiting_provider still prefers
            // triaged_at (when it first became provider-ready) over
            // checked_in_at, unchanged.
            $waitStartedAt = match ($stage) {
                AppointmentStatus::WAITING_PROVIDER->value => $appointment->triaged_at ?? $appointment->checked_in_at,
                AppointmentStatus::IN_CONSULTATION->value => $appointment->consultation_started_at,
                default => $appointment->checked_in_at,
            };
            $patient = $patientsById->get($appointment->patient_id);
            $patientName = $patient !== null
                ? implode(' ', array_filter([
                    $patient->first_name,
                    $patient->middle_name,
                    $patient->last_name,
                ], static fn (?string $part): bool => $part !== null && trim($part) !== ''))
                : null;

            return [
                'appointmentId' => $appointment->id,
                'appointmentNumber' => $appointment->appointment_number,
                'status' => $appointment->status,
                'patientId' => $appointment->patient_id,
                'patientName' => $patientName !== '' ? $patientName : null,
                'patientNumber' => $patient?->patient_number,
                'department' => $appointment->department,
                'clinicianUserId' => $appointment->clinician_user_id,
                'triageOwnerUserId' => $appointment->triage_owner_user_id,
                'triageOwnerAssignedAt' => $appointment->triage_owner_assigned_at,
                'consultationOwnerUserId' => $appointment->consultation_owner_user_id,
                'consultationStartedAt' => $appointment->consultation_started_at,
                'hasSignedConsultationNote' => $signedNoteByAppointmentId[$appointment->id] ?? false,
                'consultationStep' => $consultationStepByAppointmentId[$appointment->id]['step'] ?? null,
                'arrivalMode' => $arrivalMode,
                'tier' => $arrivalMode !== null
                    ? (self::ARRIVAL_MODE_TIERS[$arrivalMode] ?? self::UNKNOWN_ARRIVAL_MODE_TIER)
                    : self::UNKNOWN_ARRIVAL_MODE_TIER,
                // Volume 3.7 T5.5 — set by ReorderReceptionQueueUseCase, reset
                // to null on every status change (UpdateAppointmentStatusUseCase).
                'queuePosition' => $appointment->queue_position,
                'waitStartedAt' => $waitStartedAt,
                // (int) cast, not just diffInMinutes(): Carbon returns a
                // float here (sub-minute precision), which without rounding
                // surfaced as "16h 42.178472083333304m wait" on the frontend
                // — a wait time is only ever meaningful to whole minutes.
                'waitMinutes' => $waitStartedAt !== null ? (int) $waitStartedAt->diffInMinutes(now()) : null,
            ];
        })->all();
    }
}
