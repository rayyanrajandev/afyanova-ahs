<?php

/**
 * The other half of the worklist predicate.
 *
 * NursingWorklistVisibilityTest covers the encounter-status half — the one that
 * lost a patient. This covers the rest: assessment removes a patient, visits
 * with no appointment behind them still belong to somebody, and an admitted
 * patient reads as admitted rather than as a queue entry.
 *
 * None of it had a test before (2026-08-19 workspace maturity audit, D4). The
 * assessment clause in particular is a `whereNotExists` that could silently
 * stop matching in exactly the way the status clause did.
 */

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Encounter\Domain\ValueObjects\EncounterStatus;
use App\Modules\ServiceRequest\Infrastructure\Models\ServiceRequestModel;
use Tests\Feature\Nursing\NursingTestSupport;

it('removes a patient from the worklist once a nurse has assessed them', function (): void {
    $nurse = NursingTestSupport::nurse();
    $assessed = NursingTestSupport::visit();
    $pending = NursingTestSupport::visit();

    NursingTestSupport::assess($assessed, $nurse->id);

    $ids = NursingTestSupport::worklistIds(
        $this->actingAs($nurse)->getJson('/api/v1/nursing/tasks')->assertOk()
    );

    expect($ids)->not->toContain($assessed['encounterId'])
        ->and($ids)->toContain($pending['encounterId']);
});

it('keeps a patient on the worklist while their assessment is still unfinished', function (): void {
    // A service request without assessed_by_user_id is work in flight, not
    // work done — the predicate turns on the assessor, not on the row.
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit();

    ServiceRequestModel::query()->create([
        'request_number' => 'SR-UNFINISHED',
        'patient_id' => $visit['patientId'],
        'appointment_id' => $visit['appointmentId'],
        'encounter_id' => $visit['encounterId'],
        'service_type' => 'nursing_assessment',
        'priority' => 'routine',
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    $ids = NursingTestSupport::worklistIds(
        $this->actingAs($nurse)->getJson('/api/v1/nursing/tasks')->assertOk()
    );

    expect($ids)->toContain($visit['encounterId']);
});

it('lists a direct-service visit that has no appointment behind it', function (): void {
    // Walk-ins seen without an appointment are still somebody's responsibility;
    // their stage is simply unknown, which is not the same as absent.
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit(['withAppointment' => false]);

    $response = $this->actingAs($nurse)->getJson('/api/v1/nursing/tasks')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $visit['encounterId']);

    expect($row)->not->toBeNull()
        ->and($row['appointmentId'])->toBeNull()
        ->and($row['visit']['stage'])->toBeNull();
});

it('reads an admitted patient as admitted rather than as a queue stage', function (): void {
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit(['encounterType' => 'inpatient']);

    $response = $this->actingAs($nurse)->getJson('/api/v1/nursing/tasks')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $visit['encounterId']);

    expect($row['visit']['stage'])->toBe('admitted_inpatient')
        ->and($row['visit']['encounterType'])->toBe('inpatient');
});

it('reports the appointment stage on the row rather than the tab it came from', function (): void {
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit([
        'appointmentStatus' => AppointmentStatus::WAITING_PROVIDER->value,
    ]);

    $response = $this->actingAs($nurse)->getJson('/api/v1/nursing/tasks')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $visit['encounterId']);

    expect($row['visit']['stage'])->toBe('waiting_clinician')
        ->and($row['visit']['appointmentStatus'])->toBe(AppointmentStatus::WAITING_PROVIDER->value);
});

it('carries reception readiness through to the nursing row', function (): void {
    // The desk verifies coverage before the patient walks through; nursing sees
    // what was verified, which is the whole point of the readiness block.
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit(['arrivalNotes' => '[08:00 Reception]: NHIF card sighted']);

    $response = $this->actingAs($nurse)->getJson('/api/v1/nursing/tasks')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $visit['encounterId']);

    expect($row['readiness']['coverageType'])->toBe('self_pay')
        ->and($row['readiness']['verificationNotes'])->toBe('[08:00 Reception]: NHIF card sighted')
        ->and($row['visit']['arrivalMode'])->toBe('walk_in');
});

it('agrees with the patient header about which visit a patient is on', function (): void {
    // The worklist row and the header that opens from it are built by the same
    // resolver; a nurse must never see two different stages for one patient.
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit([
        'encounterStatus' => EncounterStatus::IN_PROGRESS->value,
        'appointmentStatus' => AppointmentStatus::WAITING_PROVIDER->value,
    ]);

    $row = collect(
        $this->actingAs($nurse)->getJson('/api/v1/nursing/tasks')->assertOk()->json('data')
    )->firstWhere('id', $visit['encounterId']);

    $header = $this->actingAs($nurse)
        ->getJson("/api/v1/nursing/active-visit/{$visit['patientId']}")
        ->assertOk();

    expect($header->json('data.encounterId'))->toBe($row['id'])
        ->and($header->json('data.visit.stage'))->toBe($row['visit']['stage'])
        ->and($header->json('data.readiness.coverageType'))->toBe($row['readiness']['coverageType']);
});
