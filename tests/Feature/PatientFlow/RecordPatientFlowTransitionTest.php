<?php

use App\Models\User;
use App\Modules\Appointment\Application\UseCases\UpdateAppointmentStatusUseCase;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\PatientFlow\Application\Services\RecordPatientFlowTransitionService;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\PatientFlow\Application\UseCases\GetActiveVisitJourneyUseCase;
use App\Modules\PatientFlow\Application\UseCases\GetPatientFlowTimelineUseCase;
use App\Modules\PatientFlow\Application\UseCases\ReleasePatientFromNursingUseCase;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use App\Modules\PatientFlow\Infrastructure\Models\PatientFlowEventModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Covers the flow log introduced by the 2026-08-16 patient-flow audit: that a
 * transition is *recorded*, not re-derived, and that the two bugs the audit
 * named can no longer happen silently.
 */
function makeFlowLogPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'last_name' => 'Mushi',
        'gender' => 'female',
        'date_of_birth' => '1988-04-02',
        'phone' => '+255700000123',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function makeFlowLogAppointment(string $patientId, string $status = 'waiting_provider'): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Visit',
        'status' => $status,
    ]);
}

it('records a transition with the actor, the step and the source that caused it', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id);

    app(RecordPatientFlowTransitionService::class)->record(
        toStep: PatientFlowStep::WITH_CLINICIAN,
        patientId: (string) $patient->id,
        appointmentId: (string) $appointment->id,
        actorId: null,
        source: 'clinician.start_consultation',
    );

    $event = PatientFlowEventModel::query()->first();

    expect($event)->not->toBeNull()
        ->and($event->to_step)->toBe('with_clinician')
        ->and($event->source)->toBe('clinician.start_consultation')
        ->and($event->patient_id)->toBe($appointment->patient_id)
        ->and($event->occurred_at)->not->toBeNull();
});

it('resolves from_step from the previous event rather than asking the caller to track it', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id);
    $service = app(RecordPatientFlowTransitionService::class);

    $service->record(
        toStep: PatientFlowStep::WAITING_CLINICIAN,
        patientId: (string) $patient->id,
        appointmentId: (string) $appointment->id,
        source: 'triage.handoff_recorded',
    );
    $service->record(
        toStep: PatientFlowStep::WITH_CLINICIAN,
        patientId: (string) $patient->id,
        appointmentId: (string) $appointment->id,
        source: 'clinician.start_consultation',
    );

    $second = PatientFlowEventModel::query()->orderByDesc('occurred_at')->orderByDesc('id')->first();

    expect($second->from_step)->toBe('waiting_clinician')
        ->and($second->to_step)->toBe('with_clinician');
});

it('does not append a row when the patient is already in that step', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id);
    $service = app(RecordPatientFlowTransitionService::class);

    foreach ([1, 2, 3] as $ignored) {
        $service->record(
            toStep: PatientFlowStep::WITH_CLINICIAN,
            patientId: (string) $patient->id,
            appointmentId: (string) $appointment->id,
            source: 'clinician.start_consultation',
        );
    }

    expect(PatientFlowEventModel::query()->count())->toBe(1);
});

it('never fails the clinical action when the transition cannot be logged', function (): void {
    // No appointment and no service request — unrecordable. The caller must
    // still get a null rather than an exception, because a doctor starting a
    // consultation must not see an error from the logging layer.
    $result = app(RecordPatientFlowTransitionService::class)->record(
        toStep: PatientFlowStep::WITH_CLINICIAN,
        patientId: (string) makeFlowLogPatient()->id,
        source: 'clinician.start_consultation',
    );

    expect($result)->toBeNull()
        ->and(PatientFlowEventModel::query()->count())->toBe(0);
});

it('writes a flow event whenever an appointment status changes through the guarded use case', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id, 'waiting_provider');

    app(UpdateAppointmentStatusUseCase::class)->execute(
        id: (string) $appointment->id,
        status: 'in_consultation',
        reason: null,
        actorId: null,
        statusAttributes: ['consultation_started_at' => now()],
        flowSource: 'clinician.start_consultation',
    );

    $event = PatientFlowEventModel::query()->where('appointment_id', $appointment->id)->first();

    expect($event)->not->toBeNull()
        ->and($event->to_step)->toBe('with_clinician')
        ->and($event->source)->toBe('clinician.start_consultation');
});

it('maps waiting_provider to review or first-consultation depending on recorded history', function (): void {
    expect(PatientFlowStep::fromAppointmentStatus('waiting_provider', hasConsultationStarted: false))
        ->toBe(PatientFlowStep::WAITING_CLINICIAN)
        ->and(PatientFlowStep::fromAppointmentStatus('waiting_provider', hasConsultationStarted: true))
        ->toBe(PatientFlowStep::WAITING_CLINICIAN_REVIEW)
        ->and(PatientFlowStep::fromAppointmentStatus('waiting_triage', hasTriageOwner: true))
        ->toBe(PatientFlowStep::IN_TRIAGE)
        // SCHEDULED is not a flow step — the patient has not arrived.
        ->and(PatientFlowStep::fromAppointmentStatus('scheduled'))->toBeNull();
});

it('knows which steps mean a patient is actively with a member of staff', function (): void {
    // The ticket's acceptance criterion: no patient can be actively with a
    // doctor or nurse while still showing an earlier waiting status.
    expect(PatientFlowStep::WITH_CLINICIAN->isActiveContact())->toBeTrue()
        ->and(PatientFlowStep::WITH_NURSE->isActiveContact())->toBeTrue()
        ->and(PatientFlowStep::IN_TRIAGE->isActiveContact())->toBeTrue()
        ->and(PatientFlowStep::WAITING_CLINICIAN->isActiveContact())->toBeFalse()
        ->and(PatientFlowStep::WAITING_TRIAGE->isActiveContact())->toBeFalse();
});

it('returns a patient timeline newest first and a visit timeline oldest first', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id);
    $service = app(RecordPatientFlowTransitionService::class);

    $service->record(
        toStep: PatientFlowStep::WAITING_CLINICIAN,
        patientId: (string) $patient->id,
        appointmentId: (string) $appointment->id,
        source: 'triage.handoff_recorded',
    );
    $service->record(
        toStep: PatientFlowStep::WITH_CLINICIAN,
        patientId: (string) $patient->id,
        appointmentId: (string) $appointment->id,
        source: 'clinician.start_consultation',
    );

    $timeline = app(GetPatientFlowTimelineUseCase::class);

    $forPatient = $timeline->forPatient((string) $patient->id);
    $forVisit = $timeline->forVisit((string) $appointment->id, null);

    expect($forPatient['data'])->toHaveCount(2)
        ->and($forPatient['data'][0]['to_step'])->toBe('with_clinician')
        ->and($forVisit)->toHaveCount(2)
        ->and($forVisit[0]['to_step'])->toBe('waiting_clinician');
});

/**
 * The regression this file exists to prevent recurring (2026-08-16): the
 * service swallows failures so a broken flow log never fails a clinical
 * action — but on PostgreSQL a failed statement aborts the whole surrounding
 * transaction, so swallowing without isolation converted "flow log
 * unavailable" into "every later statement in the caller's transaction dies
 * with SQLSTATE 25P02". A walk-in check-in failed on its arrival_events
 * insert because a flow-event insert had already poisoned the transaction.
 *
 * The append now runs in its own savepoint. These tests assert the caller's
 * transaction survives and still commits.
 */
it('leaves the caller transaction usable when the flow log write fails', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id);

    // Make the append fail the way a missing table or a rejected RLS policy would.
    Schema::drop('patient_flow_events');

    $committed = DB::transaction(function () use ($patient, $appointment): bool {
        $result = app(RecordPatientFlowTransitionService::class)->record(
            toStep: PatientFlowStep::WITH_CLINICIAN,
            patientId: (string) $patient->id,
            appointmentId: (string) $appointment->id,
            source: 'clinician.start_consultation',
        );

        expect($result)->toBeNull();

        // The clinical work the caller was actually doing must still go through.
        $appointment->update(['status' => 'in_consultation']);

        return true;
    });

    expect($committed)->toBeTrue()
        ->and($appointment->fresh()->status)->toBe('in_consultation');
});

it('does not abort a caller transaction that writes after a failed flow log', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id);

    Schema::drop('patient_flow_events');

    DB::transaction(function () use ($patient, $appointment): void {
        app(RecordPatientFlowTransitionService::class)->record(
            toStep: PatientFlowStep::WITH_CLINICIAN,
            patientId: (string) $patient->id,
            appointmentId: (string) $appointment->id,
            source: 'clinician.start_consultation',
        );

        // This is the arrival_events-shaped statement that used to die with
        // "current transaction is aborted, commands ignored until end of
        // transaction block".
        DB::table('appointments')
            ->where('id', $appointment->id)
            ->update(['status_reason' => 'still writable']);
    });

    expect($appointment->fresh()->status_reason)->toBe('still writable');
});

/**
 * Badge coverage (2026-08-16): the nursing pickup previously wrote only to the
 * best-effort flow log, so no queue could render it — the button changed
 * nothing on screen. Current state now lives on nursing_contact_user_id, which
 * every queue reads through the one shared PatientFlowStep resolver.
 */
it('resolves with_nurse from the ownership column, for every queue at once', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id, 'waiting_provider');

    expect(PatientFlowStep::forAppointment($appointment))->toBe(PatientFlowStep::WAITING_CLINICIAN);

    $nurse = User::factory()->create();
    $appointment->forceFill(['nursing_contact_user_id' => $nurse->id, 'nursing_contact_started_at' => now()])->save();

    expect(PatientFlowStep::forAppointment($appointment->fresh()))->toBe(PatientFlowStep::WITH_NURSE);
});

it('never lets a nursing claim mask an active consultation', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id, 'in_consultation');
    $nurse = User::factory()->create();
    $appointment->forceFill(['nursing_contact_user_id' => $nurse->id, 'nursing_contact_started_at' => now()])->save();

    // A patient in a consultation room is with the doctor even if a nurse is
    // also present — and a stale claim must never hide that.
    expect(PatientFlowStep::forAppointment($appointment->fresh()))->toBe(PatientFlowStep::WITH_CLINICIAN);
});

it('puts the patient back in their real queue when the nurse releases them', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id, 'waiting_provider');
    $nurse = User::factory()->create();
    $appointment->forceFill(['nursing_contact_user_id' => $nurse->id, 'nursing_contact_started_at' => now()])->save();

    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'opened',
        'type' => 'ambulatory',
        'opened_at' => now(),
    ]);

    $result = app(ReleasePatientFromNursingUseCase::class)->execute((string) $encounter->id, actorId: $nurse->id);

    expect($result['step'])->toBe('waiting_clinician')
        ->and($appointment->fresh()->nursing_contact_user_id)->toBeNull()
        ->and(PatientFlowStep::forAppointment($appointment->fresh()))->toBe(PatientFlowStep::WAITING_CLINICIAN);
});

it('fills stepEnteredAt for waiting_clinician from the flow log', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id, 'waiting_provider');

    // The board has no column marking when a patient entered waiting_clinician,
    // so this used to be permanently null. The log records the exact moment.
    app(RecordPatientFlowTransitionService::class)->record(
        toStep: PatientFlowStep::WAITING_CLINICIAN,
        patientId: (string) $patient->id,
        appointmentId: (string) $appointment->id,
        source: 'triage.handoff_recorded',
    );

    $entry = collect(app(GetActiveVisitJourneyUseCase::class)->execute(patientId: (string) $patient->id))
        ->firstWhere('appointmentId', $appointment->id);

    expect($entry)->not->toBeNull()
        ->and($entry['step'])->toBe('waiting_clinician')
        ->and($entry['stepEnteredAt'])->not->toBeNull();
});

/**
 * Activity-timeline completeness (2026-08-16).
 *
 * The timeline presents itself as the sequence of a visit, but three real steps
 * never reached it: a triage claim and its release changed no appointment status
 * so nothing was written, and recording vitals surfaced only as the triage
 * handoff that followed it. Three of the transformer's ten source labels were
 * for events nobody emitted — the tell that the vocabulary was written before
 * the writers were wired. A silent omission on an audit surface is worse than a
 * wrong label.
 */
it('records a triage claim, which changes no appointment status', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id, 'waiting_triage');
    $nurse = User::factory()->create();

    app(\App\Modules\Appointment\Application\UseCases\ClaimAppointmentTriageUseCase::class)
        ->execute((string) $appointment->id, $nurse->id);

    $event = PatientFlowEventModel::query()->where('source', 'triage.claimed')->first();

    expect($event)->not->toBeNull()
        ->and($event->to_step)->toBe('in_triage')
        ->and($event->actor_user_id)->toBe($nurse->id)
        // The status is untouched — the claim is metadata alongside it.
        ->and($appointment->fresh()->status)->toBe('waiting_triage');
});

it('records the matching release, so a pickup never dangles', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id, 'waiting_triage');
    $nurse = User::factory()->create();

    app(\App\Modules\Appointment\Application\UseCases\ClaimAppointmentTriageUseCase::class)
        ->execute((string) $appointment->id, $nurse->id);
    app(\App\Modules\Appointment\Application\UseCases\ReleaseAppointmentTriageClaimUseCase::class)
        ->execute((string) $appointment->id, $nurse->id);

    $release = PatientFlowEventModel::query()->where('source', 'triage.claim_released')->first();

    expect($release)->not->toBeNull()
        ->and($release->from_step)->toBe('in_triage')
        ->and($release->to_step)->toBe('waiting_triage');
});

it('stamps the role the actor was acting as', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id);
    $nurse = User::factory()->create();

    $role = \App\Modules\Platform\Infrastructure\Models\RoleModel::query()->create([
        'code' => 'CLINICAL.NURSE',
        'name' => 'Nurse Officer',
        'status' => 'active',
        'is_system' => true,
        'access_level' => 'request',
        'scope_type' => 'facility',
    ]);
    $nurse->roles()->syncWithoutDetaching([$role->id]);

    app(RecordPatientFlowTransitionService::class)->record(
        toStep: PatientFlowStep::WITH_NURSE,
        patientId: (string) $patient->id,
        appointmentId: (string) $appointment->id,
        actorId: $nurse->id,
        source: 'nursing.patient_claimed',
    );

    // Was permanently null: the column and API field existed, no caller ever set them.
    expect(PatientFlowEventModel::query()->first()->actor_role)->toBe('clinical.nurse');
});

it('records a same-step event when the caller says it is real work', function (): void {
    // Vitals leave the visit exactly where it was, but are still dated,
    // attributable work that belongs on the timeline.
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id, 'waiting_triage');
    $service = app(RecordPatientFlowTransitionService::class);

    $service->record(
        toStep: PatientFlowStep::WAITING_TRIAGE,
        patientId: (string) $patient->id,
        appointmentId: (string) $appointment->id,
        source: 'nursing.vitals_recorded',
        allowSameStep: true,
    );

    expect(PatientFlowEventModel::query()->where('source', 'nursing.vitals_recorded')->count())->toBe(1);

    // Without the flag the no-op guard still protects the log from noise.
    $service->record(
        toStep: PatientFlowStep::WAITING_TRIAGE,
        patientId: (string) $patient->id,
        appointmentId: (string) $appointment->id,
        source: 'appointment.status_updated',
    );

    expect(PatientFlowEventModel::query()->count())->toBe(1);
});

it('resolves encounter_id for appointment when omitted by caller', function (): void {
    $patient = makeFlowLogPatient();
    $appointment = makeFlowLogAppointment($patient->id);
    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'opened',
        'type' => 'ambulatory',
        'opened_at' => now(),
    ]);

    app(RecordPatientFlowTransitionService::class)->record(
        toStep: PatientFlowStep::WAITING_CLINICIAN,
        patientId: (string) $patient->id,
        appointmentId: (string) $appointment->id,
        source: 'triage.handoff_recorded',
    );

    $event = PatientFlowEventModel::query()->where('source', 'triage.handoff_recorded')->first();

    expect($event)->not->toBeNull()
        ->and($event->encounter_id)->toBe($encounter->id);
});

