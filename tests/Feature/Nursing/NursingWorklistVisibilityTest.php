<?php

/**
 * A patient must not disappear from the nursing worklist while their visit is live.
 *
 * This is the regression guard for a real incident, reconstructed from the
 * database on 2026-08-19:
 *
 *   15:18  Reception checks the patient in — encounter created `opened`,
 *          patient visible to nursing.
 *   15:42  A clinician saves a *draft* note on that encounter.
 *   15:51  EncounterLifecycleService promotes the encounter to `in_progress`.
 *          The patient vanishes from the nursing worklist.
 *
 * Nothing errored, nothing logged, no test failed. The nursing queries matched
 * `status = 'opened'` exactly, so a documentation state change silently
 * evicted a patient from a clinical work queue. The nurse would have had to
 * notice the absence of someone they were never told to expect.
 *
 * See reports/workspace-maturity/03-nursing.md, goal G1.
 */

use App\Modules\Encounter\Domain\ValueObjects\EncounterStatus;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use Tests\Feature\Nursing\NursingTestSupport;

it('keeps a patient on the nursing worklist after a clinician saves a draft note', function (): void {
    // The incident itself. `in_progress` is what a draft note promotes the
    // encounter to; before the fix this patient was invisible to nursing.
    $visit = NursingTestSupport::visit(['encounterStatus' => EncounterStatus::IN_PROGRESS->value]);

    $response = $this->actingAs(NursingTestSupport::nurse())->getJson('/api/v1/nursing/tasks')->assertOk();

    expect(collect($response->json('data'))->pluck('id'))->toContain($visit['encounterId']);
});

it('shows a freshly checked-in patient on the nursing worklist', function (): void {
    $visit = NursingTestSupport::visit(['encounterStatus' => EncounterStatus::OPENED->value]);

    $response = $this->actingAs(NursingTestSupport::nurse())->getJson('/api/v1/nursing/tasks')->assertOk();

    expect(collect($response->json('data'))->pluck('id'))->toContain($visit['encounterId']);
});

it('still lists a patient whose notes are awaiting signature', function (): void {
    $visit = NursingTestSupport::visit(['encounterStatus' => EncounterStatus::READY_FOR_SIGN->value]);

    $response = $this->actingAs(NursingTestSupport::nurse())->getJson('/api/v1/nursing/tasks')->assertOk();

    expect(collect($response->json('data'))->pluck('id'))->toContain($visit['encounterId']);
});

it('drops a patient off the worklist once their visit is genuinely over', function (): void {
    // The widened set must not become "show everything" — a closed or cancelled
    // encounter is finished work and belongs to nobody.
    $closed = NursingTestSupport::visit(['encounterStatus' => EncounterStatus::CLOSED->value]);
    $cancelled = NursingTestSupport::visit(['encounterStatus' => EncounterStatus::CANCELLED->value]);

    $response = $this->actingAs(NursingTestSupport::nurse())->getJson('/api/v1/nursing/tasks')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->not->toContain($closed['encounterId'])
        ->and($ids)->not->toContain($cancelled['encounterId']);
});

it('resolves visit context for a patient whose encounter has moved past opened', function (): void {
    // The patient header lost its context on the same trigger as the worklist.
    $visit = NursingTestSupport::visit(['encounterStatus' => EncounterStatus::IN_PROGRESS->value]);

    $response = $this->actingAs(NursingTestSupport::nurse())
        ->getJson("/api/v1/nursing/active-visit/{$visit['patientId']}")
        ->assertOk();

    expect($response->json('data.encounterId'))->toBe($visit['encounterId']);
});

it('closes an in-progress encounter when the patient is handed back to reception', function (): void {
    // Previously this silently found nothing and reported success, leaving a
    // live encounter behind.
    $visit = NursingTestSupport::visit(['encounterStatus' => EncounterStatus::IN_PROGRESS->value]);

    $this->actingAs(NursingTestSupport::nurse())
        ->postJson("/api/v1/nursing/return-to-reception/{$visit['appointmentId']}", [
            'reason' => 'Sent back for registration details',
        ])
        ->assertOk();

    expect(EncounterModel::query()->find($visit['encounterId'])->status)
        ->toBe(EncounterStatus::CANCELLED->value);
});

it('refuses to discard finalised documentation when handing a patient back', function (): void {
    // A note submitted for signature is completed clinical work. Handing the
    // patient back to reception is not authority to throw it away.
    $visit = NursingTestSupport::visit(['encounterStatus' => EncounterStatus::READY_FOR_SIGN->value]);

    $this->actingAs(NursingTestSupport::nurse())
        ->postJson("/api/v1/nursing/return-to-reception/{$visit['appointmentId']}", [
            'reason' => 'Wrong queue',
        ])
        ->assertOk();

    expect(EncounterModel::query()->find($visit['encounterId'])->status)
        ->toBe(EncounterStatus::READY_FOR_SIGN->value);
});

it('gives nursing and the clinician queue one definition of a live encounter', function (): void {
    // The drift that caused the incident: two screens answering the same
    // question from two different vocabularies.
    expect(EncounterStatus::liveStatuses())->toBe(['opened', 'in_progress', 'ready_for_sign'])
        ->and(EncounterStatus::READY_FOR_SIGN->carriesFinalisedDocumentation())->toBeTrue()
        ->and(EncounterStatus::IN_PROGRESS->carriesFinalisedDocumentation())->toBeFalse();
});
