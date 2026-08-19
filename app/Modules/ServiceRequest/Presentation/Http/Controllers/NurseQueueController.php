<?php

namespace App\Modules\ServiceRequest\Presentation\Http\Controllers;

use App\Modules\Payer\Infrastructure\Models\PatientInsuranceModel;
use App\Http\Controllers\Controller;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;
use App\Modules\ServiceRequest\Application\UseCases\CompleteNurseAssessmentUseCase;
use App\Modules\ServiceRequest\Infrastructure\Models\ServiceRequestModel;
use App\Modules\ServiceRequest\Presentation\Http\Requests\CompleteNurseAssessmentRequest;
use App\Modules\ServiceRequest\Presentation\Http\Transformers\ServiceRequestResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NurseQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) ($request->input('perPage', 20)), 1), 100);
        $page = max((int) ($request->input('page', 1)), 1);

        $encounters = EncounterModel::query()
            ->select('encounters.*')
            ->where('encounters.status', 'opened')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('service_requests')
                    ->whereColumn('service_requests.encounter_id', 'encounters.id')
                    ->whereNotNull('service_requests.assessed_by_user_id');
            })
            ->with(['patient' => function ($query) {
                $query->select(['id', 'patient_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'phone']);
            }])
            ->orderBy('encounters.opened_at', 'asc')
            ->paginate(perPage: $perPage, page: $page);

        $appointmentIds = $encounters->getCollection()
            ->pluck('appointment_id')
            ->filter()
            ->unique()
            ->values();

        $patientIds = $encounters->getCollection()
            ->pluck('patient_id')
            ->filter()
            ->unique()
            ->values();

        // Batch-load appointments, arrival modes, and insurance records so the
        // visit/readiness context below doesn't trigger N+1 queries per row.
        $appointments = $appointmentIds->isNotEmpty()
            ? AppointmentModel::query()
                ->whereIn('id', $appointmentIds)
                ->get()
                ->keyBy('id')
            : collect();

        $arrivalModes = $appointmentIds->isNotEmpty()
            ? ArrivalEventModel::query()
                ->whereIn('appointment_id', $appointmentIds)
                ->orderByDesc('arrived_at')
                ->get()
                ->groupBy('appointment_id')
                ->map->first()
            : collect();

        $insuranceRecords = $patientIds->isNotEmpty()
            ? PatientInsuranceModel::query()
                ->whereIn('patient_id', $patientIds)
                ->where('status', 'active')
                ->get()
                ->groupBy('patient_id')
                ->map->first()
            : collect();

        $data = $encounters->map(function (EncounterModel $encounter) use ($appointments, $arrivalModes, $insuranceRecords): array {
            $patient = $encounter->patient;
            $age = null;
            if ($patient && $patient->date_of_birth) {
                $age = $patient->date_of_birth->diffInYears(now());
            }

            $appointment = $appointments->get($encounter->appointment_id);
            $stage = $this->deriveVisitStage($appointment, $encounter);
            $arrivalEvent = $arrivalModes->get($encounter->appointment_id);
            $insurance = $insuranceRecords->get($encounter->patient_id);

            $insuranceVerified = $insurance
                ? ($insurance->verification_status === 'verified')
                : null;

            return [
                'id' => $encounter->id,
                'encounterNumber' => $encounter->encounter_number,
                'patientId' => $encounter->patient_id,
                'appointmentId' => $encounter->appointment_id,
                'status' => $encounter->status,
                'type' => $encounter->type,
                'openedAt' => $encounter->opened_at?->toISOString(),
                'visit' => [
                    'appointmentStatus' => $appointment?->status,
                    'stage' => $stage,
                    'arrivalMode' => $arrivalEvent?->arrival_mode,
                    'visitCategory' => $encounter->visit_category,
                    'encounterType' => $encounter->type,
                    'isAdmitted' => $encounter->admission_id !== null,
                ],
                'readiness' => [
                    'coverageType' => $appointment?->financial_coverage_type,
                    'insuranceVerified' => $insuranceVerified,
                    'insuranceProvider' => $insurance?->insurance_provider,
                    'verificationNotes' => $arrivalEvent?->verification_notes,
                ],
                'patient' => $patient ? [
                    'id' => $patient->id,
                    'patientNumber' => $patient->patient_number,
                    'firstName' => $patient->first_name,
                    'middleName' => $patient->middle_name,
                    'lastName' => $patient->last_name,
                    'dateOfBirth' => $patient->date_of_birth?->toISOString(),
                    'gender' => $patient->gender,
                    'phone' => $patient->phone,
                    'age' => $age,
                ] : null,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'currentPage' => $encounters->currentPage(),
                'perPage' => $encounters->perPage(),
                'total' => $encounters->total(),
                'lastPage' => $encounters->lastPage(),
            ],
        ]);
    }

    /**
     * Derive the fine-grained visit stage from the appointment's workflow
     * status, mirroring PatientFlow's GetActiveVisitJourneyUseCase derivation
     * (waiting_triage / in_triage / waiting_clinician / ...) so the nursing
     * UI can show where this patient is in their journey.
     *
     * Returns null when the encounter has no linked appointment (e.g. a
     * direct-service or admission-driven encounter with no appointment).
     */
    private function deriveVisitStage(?AppointmentModel $appointment, ?EncounterModel $encounter = null): ?string
    {
        if ($encounter !== null && ($encounter->admission_id !== null || $encounter->type === 'inpatient')) {
            return 'admitted_inpatient';
        }

        if ($appointment === null) {
            return null;
        }

        // Delegated to PatientFlowStep (2026-08-16 flow audit): this was one of
        // three near-identical copies of the same mapping, none of which knew
        // about nursing pickup — so a nurse actively with a patient still read
        // as "waiting" on the very queue that nurse was working from.
        return PatientFlowStep::forAppointment($appointment)?->value
            ?? $appointment->status;
    }

    public function assess(
        string $encounterId,
        CompleteNurseAssessmentRequest $request,
        CompleteNurseAssessmentUseCase $useCase,
    ): JsonResponse {
        $validated = $request->validated();

        $order = $useCase->execute(
            encounterId: $encounterId,
            clinicalNote: $validated['clinicalNote'],
            items: $validated['items'],
            actorId: $request->user()?->id,
        );

        return response()->json([
            'data' => ServiceRequestResponseTransformer::transform($order),
        ], 201);
    }

    public function activeVisit(string $patientId): JsonResponse
    {
        $encounter = EncounterModel::query()
            ->where('patient_id', $patientId)
            ->where('status', 'opened')
            ->orderByDesc('opened_at')
            ->first();

        if ($encounter === null) {
            return response()->json([
                'data' => null,
            ]);
        }

        $appointment = $encounter->appointment_id !== null
            ? AppointmentModel::query()->find($encounter->appointment_id)
            : null;

        $arrivalEvent = $encounter->appointment_id !== null
            ? ArrivalEventModel::query()
                ->where('appointment_id', $encounter->appointment_id)
                ->orderByDesc('arrived_at')
                ->first()
            : null;

        $insurance = PatientInsuranceModel::query()
            ->where('patient_id', $patientId)
            ->where('status', 'active')
            ->first();

        $insuranceVerified = $insurance
            ? ($insurance->verification_status === 'verified')
            : null;

        $stage = $this->deriveVisitStage($appointment, $encounter);

        return response()->json([
            'data' => [
                'encounterId' => $encounter->id,
                'visit' => [
                    'appointmentStatus' => $appointment?->status,
                    'stage' => $stage,
                    'arrivalMode' => $arrivalEvent?->arrival_mode,
                    'visitCategory' => $encounter->visit_category,
                    'encounterType' => $encounter->type,
                    'isAdmitted' => $encounter->admission_id !== null,
                ],
                'readiness' => [
                    'coverageType' => $appointment?->financial_coverage_type,
                    'insuranceVerified' => $insuranceVerified,
                    'insuranceProvider' => $insurance?->insurance_provider,
                    'verificationNotes' => $arrivalEvent?->verification_notes,
                ],
            ],
        ]);
    }

    public function returnToReception(
        string $appointmentId,
        Request $request,
        \App\Modules\Appointment\Application\UseCases\UpdateAppointmentStatusUseCase $updateStatus,
    ): JsonResponse {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $rawReason = trim((string) $request->input('reason'));
        $formattedReason = $rawReason !== ''
            ? 'Returned to Reception: ' . $rawReason
            : 'Returned to Reception by Nursing for administrative verification';

        $resolvedAppointmentId = $appointmentId;
        $appointmentExists = \App\Modules\Appointment\Infrastructure\Models\AppointmentModel::query()
            ->where('id', $appointmentId)
            ->exists();

        if (! $appointmentExists) {
            $encounter = \App\Modules\Encounter\Infrastructure\Models\EncounterModel::query()
                ->where('id', $appointmentId)
                ->first();
            if ($encounter !== null && $encounter->appointment_id !== null) {
                $resolvedAppointmentId = $encounter->appointment_id;
            } elseif ($encounter !== null) {
                // Direct walk-in encounter with no appointment record
                $encounter->update(['status' => 'cancelled']);
                return response()->json([
                    'data' => [
                        'id' => $encounter->id,
                        'status' => 'cancelled',
                        'reason' => $formattedReason,
                    ],
                ]);
            }
        }

        $appointment = \App\Modules\Appointment\Infrastructure\Models\AppointmentModel::query()
            ->find($resolvedAppointmentId);

        abort_if($appointment === null, 404, 'Appointment or encounter not found.');

        // 1. Reset appointment status back to waiting_triage so patient remains
        //    in Reception's queue.
        //
        //    Routed through UpdateAppointmentStatusUseCase rather than a raw
        //    Eloquent update: this was the last write that set appointments.status
        //    directly, so it skipped the transition guard, wrote no audit row, and
        //    recorded no flow event — leaving `returned_to_reception` unreachable
        //    and the hand-back invisible on the Activity timeline. Same fix already
        //    applied to the vitals path (laboratory flow plan, phase 5).
        $updateStatus->execute(
            id: (string) $appointment->id,
            status: \App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus::WAITING_TRIAGE->value,
            reason: $formattedReason,
            actorId: $request->user()?->id,
            statusAttributes: [
                // Handing the patient back to reception necessarily ends any
                // nursing contact — without clearing this the visit would keep
                // reading "With Nurse" in every queue after the nurse let them go.
                'nursing_contact_user_id' => null,
                'nursing_contact_started_at' => null,
            ],
            flowSource: 'nursing.returned_to_reception',
            flowStepOverride: \App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep::RETURNED_TO_RECEPTION,
        );

        $appointment->refresh();

        // 2. Close/cancel open encounter for Nursing
        $encounter = \App\Modules\Encounter\Infrastructure\Models\EncounterModel::query()
            ->where('appointment_id', $appointment->id)
            ->where('status', 'opened')
            ->first();
        if ($encounter !== null) {
            $encounter->update(['status' => 'cancelled']);
        }

        // 3. Append note to ArrivalEvent
        $arrivalEvent = \App\Modules\Reception\Infrastructure\Models\ArrivalEventModel::query()
            ->where('appointment_id', $appointment->id)
            ->orderByDesc('arrived_at')
            ->first();
        if ($arrivalEvent !== null) {
            $existing = trim((string) $arrivalEvent->verification_notes);
            $timestamp = now()->format('H:i');
            $user = $request->user();
            $author = $user ? ($user->name ?? 'Nurse') : 'Nurse';
            $appended = $existing !== ''
                ? "{$existing}\n[{$timestamp} {$author}]: {$formattedReason}"
                : "[{$timestamp} {$author}]: {$formattedReason}";
            $arrivalEvent->update(['verification_notes' => $appended]);
        }

        // 4. Resolve patient name & facilityId
        $patient = \App\Modules\Patient\Infrastructure\Models\PatientModel::query()->find($appointment->patient_id);
        $patientName = $patient !== null ? trim("{$patient->first_name} {$patient->last_name}") : 'Patient';
        $facilityId = $request->user()?->facility_id ?? $appointment->facility_id ?? null;

        // 5. Broadcast real-time event to Reception & Patient Flow board
        event(new \App\Modules\Reception\Domain\Events\PatientReturnedToReception(
            appointmentId: $appointment->id,
            patientId: $appointment->patient_id,
            patientName: $patientName !== '' ? $patientName : 'Patient',
            reason: $rawReason !== '' ? $rawReason : 'Administrative verification',
            facilityId: $facilityId,
        ));

        event(new \App\Modules\PatientFlow\Domain\Events\PatientFlowBoardUpdated($facilityId));

        return response()->json([
            'data' => \App\Modules\Appointment\Presentation\Http\Transformers\AppointmentResponseTransformer::transform($appointment->toArray()),
        ]);
    }

    public function addVisitNote(
        string $appointmentId,
        Request $request,
    ): JsonResponse {
        $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        $arrivalEvent = ArrivalEventModel::query()
            ->where('appointment_id', $appointmentId)
            ->orderByDesc('arrived_at')
            ->first();

        if ($arrivalEvent !== null) {
            $existing = trim((string) $arrivalEvent->verification_notes);
            $newNote = trim((string) $request->input('note'));
            $user = $request->user();
            $author = $user ? ($user->name ?? 'Staff') : 'Staff';
            $timestamp = now()->format('H:i');
            $appended = $existing !== ''
                ? "{$existing}\n[{$timestamp} {$author}]: {$newNote}"
                : "[{$timestamp} {$author}]: {$newNote}";

            $arrivalEvent->update([
                'verification_notes' => $appended,
            ]);
        }

        return response()->json([
            'data' => [
                'verificationNotes' => $arrivalEvent?->verification_notes,
            ],
        ]);
    }

    public function getVisitNotes(string $appointmentId): JsonResponse
    {
        $arrivalEvent = ArrivalEventModel::query()
            ->where('appointment_id', $appointmentId)
            ->orderByDesc('arrived_at')
            ->first();

        return response()->json([
            'data' => [
                'verificationNotes' => $arrivalEvent?->verification_notes,
            ],
        ]);
    }

    public function updateVisitNotes(
        string $appointmentId,
        Request $request,
    ): JsonResponse {
        $request->validate([
            'verificationNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        $arrivalEvent = ArrivalEventModel::query()
            ->where('appointment_id', $appointmentId)
            ->orderByDesc('arrived_at')
            ->first();

        if ($arrivalEvent !== null) {
            $arrivalEvent->update([
                'verification_notes' => $request->input('verificationNotes'),
            ]);
        }

        return response()->json([
            'data' => [
                'verificationNotes' => $arrivalEvent?->verification_notes,
            ],
        ]);
    }

    public function deleteVisitNote(
        string $appointmentId,
        Request $request,
    ): JsonResponse {
        $request->validate([
            'index' => ['required', 'integer', 'min:0'],
        ]);

        $arrivalEvent = ArrivalEventModel::query()
            ->where('appointment_id', $appointmentId)
            ->orderByDesc('arrived_at')
            ->first();

        if ($arrivalEvent !== null && $arrivalEvent->verification_notes) {
            $lines = array_values(array_filter(
                array_map('trim', explode("\n", (string) $arrivalEvent->verification_notes)),
                'strlen'
            ));

            $index = (int) $request->input('index');
            if (isset($lines[$index])) {
                unset($lines[$index]);
                $updated = implode("\n", array_values($lines));
                $arrivalEvent->update([
                    'verification_notes' => $updated !== '' ? $updated : null,
                ]);
            }
        }

        return response()->json([
            'data' => [
                'verificationNotes' => $arrivalEvent?->verification_notes,
            ],
        ]);
    }
}
