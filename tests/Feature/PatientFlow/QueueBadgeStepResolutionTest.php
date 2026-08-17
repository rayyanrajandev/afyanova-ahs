<?php

use App\Modules\Encounter\Presentation\Http\Transformers\EncounterListItemResponseTransformer;
use App\Modules\Patient\Presentation\Http\Transformers\PatientSummaryResponseTransformer;
use App\Modules\Reception\Presentation\Http\Transformers\ReceptionQueueEntryResponseTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Every queue row's badge is resolved by one shared mapping (PatientFlowStep).
 * These assert the two transformers that feed the reception and clinician
 * queues actually emit it — the gap the 2026-08-16 follow-up found, where the
 * flow work was written but no badge could render it.
 */
function receptionEntry(array $overrides = []): array
{
    return array_merge([
        'appointmentId' => 'apt-1',
        'appointmentNumber' => 'APT1',
        'status' => 'waiting_provider',
        'patientId' => 'pat-1',
        'patientName' => 'Zamaradi Juma',
        'patientNumber' => 'MRN1',
        'department' => 'Outpatient',
        'clinicianUserId' => null,
        'triageOwnerUserId' => null,
        'triageOwnerAssignedAt' => null,
        'consultationOwnerUserId' => null,
        'consultationStartedAt' => null,
        'nursingContactUserId' => null,
        'nursingContactStartedAt' => null,
        'hasSignedConsultationNote' => false,
        'consultationStep' => null,
        'arrivalMode' => 'walk_in',
        'tier' => 1,
        'queuePosition' => null,
        'waitStartedAt' => null,
        'waitMinutes' => 5,
    ], $overrides);
}

it('shows with_nurse on a reception queue row once a nurse has picked the patient up', function (): void {
    $waiting = ReceptionQueueEntryResponseTransformer::transform(receptionEntry());
    expect($waiting['stage'])->toBe('waiting_clinician');

    $pickedUp = ReceptionQueueEntryResponseTransformer::transform(
        receptionEntry(['nursingContactUserId' => 7]),
    );

    // Previously this row still read "waiting_clinician" — the nurse was
    // invisible to reception entirely.
    expect($pickedUp['stage'])->toBe('with_nurse')
        ->and($pickedUp['nursingContactUserId'])->toBe(7);
});

it('keeps a reception row on with_clinician even if a nursing claim is stale', function (): void {
    $entry = ReceptionQueueEntryResponseTransformer::transform(
        receptionEntry(['status' => 'in_consultation', 'nursingContactUserId' => 7]),
    );

    expect($entry['stage'])->toBe('with_clinician');
});

it('distinguishes in_triage from waiting_triage on a reception row', function (): void {
    expect(ReceptionQueueEntryResponseTransformer::transform(
        receptionEntry(['status' => 'waiting_triage']),
    )['stage'])->toBe('waiting_triage');

    expect(ReceptionQueueEntryResponseTransformer::transform(
        receptionEntry(['status' => 'waiting_triage', 'triageOwnerUserId' => 3]),
    )['stage'])->toBe('in_triage');
});

it('emits a visitStage on clinician queue rows instead of leaving them to guess', function (): void {
    $row = EncounterListItemResponseTransformer::transform([
        'id' => 'enc-1',
        'encounter_number' => 'ENC1',
        'patient_id' => 'pat-1',
        'status' => 'opened',
        'appointment' => [
            'status' => 'waiting_provider',
            'nursing_contact_user_id' => 7,
        ],
    ]);

    // The clinician queue used to infer "in consultation" from the mere
    // existence of a note; it now renders whatever this says.
    expect($row['visitStage'])->toBe('with_nurse');
});

it('leaves visitStage null when an encounter has no appointment to resolve from', function (): void {
    $row = EncounterListItemResponseTransformer::transform([
        'id' => 'enc-2',
        'encounter_number' => 'ENC2',
        'patient_id' => 'pat-2',
        'status' => 'opened',
        'appointment' => null,
    ]);

    // Honest absence — the frontend falls back rather than showing a wrong badge.
    expect($row['visitStage'])->toBeNull();
});

it('resolves the same step for the patient profile as for the queue row', function (): void {
    // The reception profile pane derived its badge from appointment.status
    // alone, so a patient the queue showed as "With Nurse" read "Waiting for
    // Triage" in the pane right next to it. Both now come from PatientFlowStep.
    $appointment = [
        'id' => 'apt-1',
        'appointment_number' => 'APT1',
        'status' => 'waiting_provider',
        'scheduled_at' => '2026-08-16T09:00:00Z',
        'department' => 'Outpatient',
        'nursing_contact_user_id' => 7,
    ];

    $summary = PatientSummaryResponseTransformer::transform([
        'activeAppointment' => $appointment,
    ]);

    $queueRow = ReceptionQueueEntryResponseTransformer::transform(
        receptionEntry(['status' => 'waiting_provider', 'nursingContactUserId' => 7]),
    );

    expect($summary['activeAppointment']['visitStage'])->toBe('with_nurse')
        ->and($summary['activeAppointment']['visitStage'])->toBe($queueRow['stage']);
});

it('leaves the profile visitStage null when the patient has no active visit', function (): void {
    $summary = PatientSummaryResponseTransformer::transform(['activeAppointment' => null]);

    expect($summary['activeAppointment'])->toBeNull();
});
