<?php

/**
 * The signals that were missing.
 *
 * ConsultationChargeRaiser has always been deliberately fail-open: a facility
 * with a missing tariff must still be able to register patients. What it lacked
 * was any way for anyone to find out that it had fallen open — the only
 * evidence was a Log::warning in a path nobody reads, which is how the prepaid
 * consultation gate sat dead in every environment while 25 Revenue tests
 * stayed green.
 *
 * These tests assert the anomaly is now countable. They deliberately do NOT
 * assert that the charge was raised: the fail-open behaviour is correct and
 * must not change. See reports/workspace-maturity/01-revenue-cashier.md, G2.
 */

use App\Modules\Revenue\Application\Services\ConsultationChargeRaiser;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryEvent;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryReason;
use App\Modules\Revenue\Infrastructure\Models\RevenueTelemetryEventModel;
use Illuminate\Support\Str;

function telemetryAppointment(string $coverage = 'self_pay'): array
{
    return [
        'id' => (string) Str::uuid(),
        'patient_id' => (string) Str::uuid(),
        'financial_coverage_type' => $coverage,
    ];
}

it('records an anomaly when no consultation item resolves, without blocking the visit', function (): void {
    // No catalogue is seeded here, so the configured code cannot resolve —
    // exactly the production condition that went unnoticed.
    $appointment = telemetryAppointment();

    $charge = app(ConsultationChargeRaiser::class)->raiseFor($appointment, actorUserId: null);

    // Fail-open is the contract and stays the contract.
    expect($charge)->toBeNull();

    $event = RevenueTelemetryEventModel::query()
        ->where('source_workflow_id', $appointment['id'])
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->event_type)->toBe(RevenueTelemetryEvent::CHARGE_NOT_RAISED->value)
        ->and($event->reason)->toBe(RevenueTelemetryReason::NO_ITEM->value)
        ->and($event->source_kind)->toBe(ChargeSourceKind::CONSULTATION->value)
        ->and($event->patient_id)->toBe($appointment['patient_id'])
        ->and($event->detail)->toBe((string) config('revenue.consultation.default_item_code'));
});

it('records an anomaly when the payer class has no settlement path', function (): void {
    $appointment = telemetryAppointment('insurance');

    $charge = app(ConsultationChargeRaiser::class)->raiseFor($appointment, actorUserId: null);

    expect($charge)->toBeNull();

    $event = RevenueTelemetryEventModel::query()
        ->where('source_workflow_id', $appointment['id'])
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->event_type)->toBe(RevenueTelemetryEvent::CHARGE_NOT_RAISED->value)
        ->and($event->reason)->toBe(RevenueTelemetryReason::PAYER_UNIMPLEMENTED->value);
});

it('records nothing when the gate is switched off, because that is a decision and not an anomaly', function (): void {
    config()->set('revenue.prepaid_required_for.consultation', false);

    $appointment = telemetryAppointment();

    expect(app(ConsultationChargeRaiser::class)->raiseFor($appointment, actorUserId: null))->toBeNull();

    expect(RevenueTelemetryEventModel::query()->count())->toBe(0);
});

it('separates the anomalies that strand a patient from those that only cost money', function (): void {
    // Alerting should page on one and report on the other; the distinction is
    // stated once, on the enum, rather than re-derived per dashboard.
    expect(RevenueTelemetryEvent::PROMOTION_FAILED->blocksAPatient())->toBeTrue()
        ->and(RevenueTelemetryEvent::CHARGE_UNPRICED->blocksAPatient())->toBeTrue()
        ->and(RevenueTelemetryEvent::CHARGE_NOT_RAISED->blocksAPatient())->toBeFalse()
        ->and(RevenueTelemetryEvent::CHARGE_CANCEL_FAILED->blocksAPatient())->toBeFalse();
});

it('reports clean and exits zero when there is nothing to reconcile', function (): void {
    $this->artisan('revenue:reconcile')
        ->expectsOutputToContain('No revenue anomalies')
        ->assertExitCode(0);
});

it('exits non-zero when anomalies exist, so a scheduler can alert without parsing output', function (): void {
    // An uncharged consultation is the exact anomaly that went unnoticed.
    app(ConsultationChargeRaiser::class)->raiseFor(telemetryAppointment(), actorUserId: null);

    expect(RevenueTelemetryEventModel::query()->count())->toBe(1);

    $this->artisan('revenue:reconcile')->assertExitCode(1);
});
