<?php

/**
 * The running visit note, and the hand-back that writes to it.
 *
 * routes/api-workspaces.php has asserted since 2026-08-16 that
 * "NurseVisitNotesApiTest still covers it". It did not exist. The delete route
 * had already gone missing once and come back with a 405 as the only symptom,
 * which is precisely what a test asserting the door is open would have caught.
 *
 * Written alongside goal G2 (reports/workspace-maturity/03-nursing.md), which
 * moved these four endpoints out of a 497-line controller and into
 * VisitNoteLogService. Untested code should not be refactored quietly, so the
 * behaviour is pinned here first.
 */

use App\Modules\Encounter\Domain\ValueObjects\EncounterStatus;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Nursing\NursingTestSupport;

it('appends an authored, timestamped line to the visit note', function (): void {
    $visit = NursingTestSupport::visit(['withArrival' => true]);

    $response = $this->actingAs(NursingTestSupport::nurse())
        ->postJson("/api/v1/nursing/visit-notes/{$visit['appointmentId']}", [
            'note' => 'Patient reports no allergies',
        ])
        ->assertOk();

    expect($response->json('data.verificationNotes'))
        ->toMatch('/^\[\d{2}:\d{2} .+\]: Patient reports no allergies$/');
});

it('keeps earlier lines when appending', function (): void {
    $visit = NursingTestSupport::visit(['arrivalNotes' => '[08:00 Reception]: ID verified']);

    $response = $this->actingAs(NursingTestSupport::nurse())
        ->postJson("/api/v1/nursing/visit-notes/{$visit['appointmentId']}", [
            'note' => 'Vitals taken',
        ])
        ->assertOk();

    $notes = (string) $response->json('data.verificationNotes');

    expect($notes)->toContain('[08:00 Reception]: ID verified')
        ->and($notes)->toContain('Vitals taken')
        ->and(substr_count($notes, "\n"))->toBe(1);
});

it('reads the visit note back', function (): void {
    $visit = NursingTestSupport::visit(['arrivalNotes' => '[08:00 Reception]: ID verified']);

    $this->actingAs(NursingTestSupport::nurse())
        ->getJson("/api/v1/nursing/visit-notes/{$visit['appointmentId']}")
        ->assertOk()
        ->assertJsonPath('data.verificationNotes', '[08:00 Reception]: ID verified');
});

it('replaces the whole visit note when a nurse edits it as text', function (): void {
    $visit = NursingTestSupport::visit(['arrivalNotes' => '[08:00 Reception]: ID verified']);

    $this->actingAs(NursingTestSupport::nurse())
        ->putJson("/api/v1/nursing/visit-notes/{$visit['appointmentId']}", [
            'verificationNotes' => 'Corrected by nurse',
        ])
        ->assertOk()
        ->assertJsonPath('data.verificationNotes', 'Corrected by nurse');
});

it('deletes one line by index and leaves the rest', function (): void {
    // The route this covers went missing once and returned a 405; a passing
    // delete is the assertion that the door is still there.
    $visit = NursingTestSupport::visit(['arrivalNotes' => "line one\nline two\nline three"]);

    $this->actingAs(NursingTestSupport::nurse())
        ->deleteJson("/api/v1/nursing/visit-notes/{$visit['appointmentId']}", ['index' => 1])
        ->assertOk()
        ->assertJsonPath('data.verificationNotes', "line one\nline three");
});

it('leaves the note alone when the index is out of range', function (): void {
    $visit = NursingTestSupport::visit(['arrivalNotes' => 'only line']);

    $this->actingAs(NursingTestSupport::nurse())
        ->deleteJson("/api/v1/nursing/visit-notes/{$visit['appointmentId']}", ['index' => 7])
        ->assertOk()
        ->assertJsonPath('data.verificationNotes', 'only line');
});

it('records the hand-back reason on the same visit note', function (): void {
    // Return-to-reception and a nurse's own note share one formatter; before
    // the extraction the format was written out twice and free to drift.
    $visit = NursingTestSupport::visit(['withArrival' => true]);

    $this->actingAs(NursingTestSupport::nurse())
        ->postJson("/api/v1/nursing/return-to-reception/{$visit['appointmentId']}", [
            'reason' => 'NHIF card missing',
        ])
        ->assertOk();

    $notes = (string) ArrivalEventModel::query()
        ->where('appointment_id', $visit['appointmentId'])
        ->value('verification_notes');

    expect($notes)->toContain('Returned to Reception: NHIF card missing')
        ->and($notes)->toMatch('/^\[\d{2}:\d{2} .+\]: /');
});

it('closes a direct walk-in encounter that has no appointment behind it', function (): void {
    $patientId = (string) Str::uuid();

    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'first_name' => 'Direct', 'last_name' => 'Service',
        'gender' => 'male', 'date_of_birth' => '1975-01-01',
        'country_code' => 'TZ', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC'.Str::upper(Str::random(8)),
        'patient_id' => $patientId,
        'appointment_id' => null,
        'status' => EncounterStatus::OPENED->value,
        'type' => 'outpatient',
        'opened_at' => now(),
    ]);

    $this->actingAs(NursingTestSupport::nurse())
        ->postJson("/api/v1/nursing/return-to-reception/{$encounter->id}", ['reason' => 'Wrong desk'])
        ->assertOk()
        ->assertJsonPath('data.status', EncounterStatus::CANCELLED->value);

    expect(EncounterModel::query()->find($encounter->id)->status)
        ->toBe(EncounterStatus::CANCELLED->value);
});

it('404s when neither an appointment nor an encounter matches', function (): void {
    $this->actingAs(NursingTestSupport::nurse())
        ->postJson('/api/v1/nursing/return-to-reception/'.Str::uuid(), ['reason' => 'x'])
        ->assertNotFound();
});
