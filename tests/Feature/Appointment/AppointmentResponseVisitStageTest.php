<?php

use App\Modules\Appointment\Presentation\Http\Transformers\AppointmentResponseTransformer;

/*
|--------------------------------------------------------------------------
| visitStage on the appointment response.
|--------------------------------------------------------------------------
|
| The badge beside a patient's name in the clinician workspace reads the flow
| *step*, not the raw status — `status` alone cannot express a nursing pickup or
| a triage claim. Every action that changes a visit returns this transformer's
| shape, so while it omitted visitStage the client had nothing to update the
| badge with: after a doctor clicked Call Patient In it kept reading "Waiting
| for Clinician" until the page was reloaded and the patient summary re-fetched.
|
| Tested at the transformer rather than over HTTP on purpose: this is the
| contract that changed, and it holds for every endpoint returning an
| appointment rather than only the one the bug was reported against.
*/

it('resolves visitStage to with_clinician for an appointment in consultation', function (): void {
    $transformed = AppointmentResponseTransformer::transform([
        'id' => 'apt-1',
        'patient_id' => 'pat-1',
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(2),
        'consultation_owner_user_id' => 7,
    ]);

    // The value the profile badge binds to, and the one the start-consultation
    // response now carries back so the client never has to re-derive it.
    expect($transformed['visitStage'])->toBe('with_clinician');
});

it('reports a step the status alone cannot express', function (): void {
    $transformed = AppointmentResponseTransformer::transform([
        'id' => 'apt-1',
        'patient_id' => 'pat-1',
        // A nurse has physically picked this patient up. `status` stays
        // waiting_triage throughout, which is exactly why a badge driven from
        // the status field showed the wrong thing.
        'status' => 'waiting_triage',
        'nursing_contact_user_id' => 3,
        'nursing_contact_started_at' => now()->subMinute(),
    ]);

    expect($transformed['status'])->toBe('waiting_triage');
    expect($transformed['visitStage'])->toBe('with_nurse');
});

it('distinguishes a claimed triage from one nobody has picked up', function (): void {
    $unclaimed = AppointmentResponseTransformer::transform([
        'id' => 'apt-1',
        'patient_id' => 'pat-1',
        'status' => 'waiting_triage',
    ]);

    $claimed = AppointmentResponseTransformer::transform([
        'id' => 'apt-2',
        'patient_id' => 'pat-1',
        'status' => 'waiting_triage',
        'triage_owner_user_id' => 5,
    ]);

    expect($unclaimed['visitStage'])->toBe('waiting_triage');
    expect($claimed['visitStage'])->toBe('in_triage');
});

it('separates a returning visit from one that has never seen a doctor', function (): void {
    $firstTime = AppointmentResponseTransformer::transform([
        'id' => 'apt-1',
        'patient_id' => 'pat-1',
        'status' => 'waiting_provider',
    ]);

    $returning = AppointmentResponseTransformer::transform([
        'id' => 'apt-2',
        'patient_id' => 'pat-1',
        'status' => 'waiting_provider',
        // Sent out for orders and coming back.
        'consultation_started_at' => now()->subMinutes(20),
    ]);

    expect($firstTime['visitStage'])->toBe('waiting_clinician');
    expect($returning['visitStage'])->toBe('waiting_clinician_review');
});

it('leaves visitStage null when the appointment has no status to resolve from', function (): void {
    $transformed = AppointmentResponseTransformer::transform([
        'id' => 'apt-1',
        'patient_id' => 'pat-1',
    ]);

    // Null rather than a guess — the same contract PatientFlowStep::forAppointment()
    // already holds for every other consumer.
    expect($transformed['visitStage'])->toBeNull();
});
