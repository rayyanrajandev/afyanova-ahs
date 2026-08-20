<?php

/**
 * Recording observations, and what it is and is not allowed to move.
 *
 * These are two separate questions and were answered as one: the visit that
 * observations *belong to* was resolved with the same query that decided
 * whether the visit could *advance*. A visit that could not advance therefore
 * lost its timeline entry as well.
 *
 * After the prepaid gate shipped that became visible: a nurse could take
 * observations on a patient waiting at the cashier, the vitals would save, and
 * the Activity tab would show nothing at all. The record of taking them was
 * dropped silently — no error, no log.
 *
 * Reported from a live environment, 2026-08-19.
 */

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\PatientVitals\Infrastructure\Models\PatientVitalSetModel;
use App\Modules\ServiceRequest\Application\Services\NursingVisitContextResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Nursing\NursingTestSupport;

function recordVitalsFor(array $visit)
{
    return test()->actingAs(NursingTestSupport::nurse())
        ->postJson('/api/v1/nursing/vitals', [
            'patientId' => $visit['patientId'],
            'appointmentId' => $visit['appointmentId'],
            'temperatureC' => 36.8,
            'systolicBpMmhg' => 120,
            'diastolicBpMmhg' => 80,
            'heartRateBpm' => 78,
        ]);
}

function vitalsTimelineEntriesFor(array $visit): int
{
    return DB::table('patient_flow_events')
        ->where('appointment_id', $visit['appointmentId'])
        ->where('source', 'nursing.vitals_recorded')
        ->count();
}

it('records observations on the timeline even when the visit cannot advance', function (): void {
    // The reported bug. The vitals saved; the fact that anyone had taken them
    // did not reach the Activity tab.
    $visit = NursingTestSupport::visit([
        'appointmentStatus' => AppointmentStatus::AWAITING_PAYMENT->value,
    ]);

    recordVitalsFor($visit)->assertCreated();

    expect(PatientVitalSetModel::query()->where('patient_id', $visit['patientId'])->count())->toBe(1)
        ->and(vitalsTimelineEntriesFor($visit))->toBe(1);
});

it('does not send an unpaid patient to the doctor just because vitals were taken', function (): void {
    // The half that must not change: observations are not payment.
    $visit = NursingTestSupport::visit([
        'appointmentStatus' => AppointmentStatus::AWAITING_PAYMENT->value,
    ]);

    recordVitalsFor($visit)->assertCreated();

    expect(AppointmentModel::query()->find($visit['appointmentId'])->status)
        ->toBe(AppointmentStatus::AWAITING_PAYMENT->value);
});

it('still hands a triaged patient to the provider queue', function (): void {
    // No regression on the ordinary path: recording vitals is what completes
    // triage for a patient who is in it.
    $visit = NursingTestSupport::visit([
        'appointmentStatus' => AppointmentStatus::WAITING_TRIAGE->value,
    ]);

    recordVitalsFor($visit)->assertCreated();

    expect(AppointmentModel::query()->find($visit['appointmentId'])->status)
        ->toBe(AppointmentStatus::WAITING_PROVIDER->value)
        ->and(vitalsTimelineEntriesFor($visit))->toBe(1);
});

it('does not pull a patient out of the consulting room to re-triage them', function (): void {
    // IN_CONSULTATION is a live visit, so observations belong to it — but
    // advancing from there used to reset the patient to waiting_provider.
    $visit = NursingTestSupport::visit([
        'appointmentStatus' => AppointmentStatus::IN_CONSULTATION->value,
    ]);

    recordVitalsFor($visit)->assertCreated();

    expect(AppointmentModel::query()->find($visit['appointmentId'])->status)
        ->toBe(AppointmentStatus::IN_CONSULTATION->value)
        ->and(vitalsTimelineEntriesFor($visit))->toBe(1);
});

it('reports that vitals were taken on an unpaid visit, so nursing stops asking for them', function (): void {
    // The reported glitch. Vitals saved, the visit could not advance because
    // the charge had not cleared, and the header inferred "no vitals yet" from
    // the unchanged status — offering Record Vitals to a nurse who had just
    // recorded them.
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit([
        'appointmentStatus' => AppointmentStatus::AWAITING_PAYMENT->value,
    ]);

    $before = test()->actingAs($nurse)
        ->getJson("/api/v1/nursing/active-visit/{$visit['patientId']}")->assertOk();
    expect($before->json('data.visit.hasRecordedVitals'))->toBeFalse();

    recordVitalsFor($visit)->assertCreated();

    $after = test()->actingAs($nurse)
        ->getJson("/api/v1/nursing/active-visit/{$visit['patientId']}")->assertOk();

    expect($after->json('data.visit.hasRecordedVitals'))->toBeTrue()
        // ...and the visit still has not advanced. Both facts at once is the
        // whole point: observations taken, payment still owed.
        ->and($after->json('data.visit.appointmentStatus'))
        ->toBe(AppointmentStatus::AWAITING_PAYMENT->value);
});

it('links a vital set to the visit even when the client does not name one', function (): void {
    // The nursing workspace posts only a patientId, so every set it recorded was
    // stored with appointment_id NULL — leaving nothing to scope the question by.
    $visit = NursingTestSupport::visit([
        'appointmentStatus' => AppointmentStatus::AWAITING_PAYMENT->value,
    ]);

    recordVitalsFor($visit)->assertCreated();

    expect(PatientVitalSetModel::query()->where('patient_id', $visit['patientId'])->value('appointment_id'))
        ->toBe($visit['appointmentId']);
});

it('says the worklist row carries the same answer as the header', function (): void {
    $nurse = NursingTestSupport::nurse();
    $visit = NursingTestSupport::visit([
        'appointmentStatus' => AppointmentStatus::AWAITING_PAYMENT->value,
    ]);

    recordVitalsFor($visit)->assertCreated();

    $row = collect(
        test()->actingAs($nurse)->getJson('/api/v1/nursing/tasks')->assertOk()->json('data')
    )->firstWhere('id', $visit['encounterId']);

    expect($row['visit']['hasRecordedVitals'])->toBeTrue();
});

it('does not let a previous visit answer for this one', function (): void {
    // Why the lookup is scoped to the appointment rather than the patient: the
    // reason the original code avoided a vitals query in the first place.
    $nurse = NursingTestSupport::nurse();
    $first = NursingTestSupport::visit(['appointmentStatus' => AppointmentStatus::WAITING_TRIAGE->value]);
    recordVitalsFor($first)->assertCreated();

    // Same patient, a new visit.
    $second = AppointmentModel::query()->create([
        'appointment_number' => 'APT-SECOND-'.Str::upper(Str::random(5)),
        'patient_id' => $first['patientId'],
        'department' => 'General',
        'scheduled_at' => now()->addDay(),
        'status' => AppointmentStatus::AWAITING_PAYMENT->value,
        'consultation_type' => 'new',
        'financial_coverage_type' => 'self_pay',
    ]);

    $resolver = app(NursingVisitContextResolver::class);

    expect($resolver->appointmentsWithRecordedVitals([(string) $second->id]))->toBe([])
        ->and($resolver->appointmentsWithRecordedVitals([$first['appointmentId']]))->not->toBe([]);
});
