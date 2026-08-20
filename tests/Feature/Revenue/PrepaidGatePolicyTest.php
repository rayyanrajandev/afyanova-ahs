<?php

/**
 * The prepaid rule, now that there is only one of it.
 *
 * Laboratory, Radiology, Pharmacy and Clinical Procedure each carried their own
 * copy of this rule, in three different shapes. The shapes disagreed: three
 * gated "every status except cancelled", one gated an explicit list. This pins
 * the surviving behaviour — the allowlist — and in particular the case the
 * denylist got wrong.
 *
 * See reports/workspace-maturity/02-clinical-order-workspaces.md, goal G2.
 */

use App\Modules\Revenue\Application\Services\PrepaidGatePolicy;
use App\Modules\Revenue\Application\UseCases\CancelServiceChargeUseCase;
use App\Modules\Revenue\Domain\Services\RevenueTelemetryRecorderInterface;
use App\Modules\Revenue\Domain\Services\ServiceAuthorizationReaderInterface;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use Illuminate\Validation\ValidationException;

function gatePolicy(bool $authorized): PrepaidGatePolicy
{
    $reader = Mockery::mock(ServiceAuthorizationReaderInterface::class);
    $reader->shouldReceive('isAuthorized')->andReturn($authorized);

    return new PrepaidGatePolicy(
        $reader,
        Mockery::mock(CancelServiceChargeUseCase::class),
        Mockery::mock(RevenueTelemetryRecorderInterface::class),
    );
}

const DELIVERY_STATUSES = ['collected', 'in_progress', 'completed'];

function assertGate(PrepaidGatePolicy $policy, string $targetStatus): void
{
    $policy->assertAuthorized(
        kind: ChargeSourceKind::LABORATORY_ORDER,
        orderId: 'order-1',
        targetStatus: $targetStatus,
        deliveryStatuses: DELIVERY_STATUSES,
        refusalMessage: 'Laboratory order cannot be processed before payment has been verified.',
    );
}

it('refuses to deliver an unpaid service', function (): void {
    assertGate(gatePolicy(authorized: false), 'in_progress');
})->throws(ValidationException::class);

it('allows delivery once the charge is authorized', function (): void {
    assertGate(gatePolicy(authorized: true), 'in_progress');

    expect(true)->toBeTrue();
});

it('never blocks cancellation of an unpaid order', function (): void {
    // A patient who cannot pay must still be able to have the order withdrawn.
    assertGate(gatePolicy(authorized: false), 'cancelled');

    expect(true)->toBeTrue();
});

it('does not gate a status the module has not declared as delivery', function (): void {
    // The point of the allowlist. Under the denylist three modules used
    // ("everything except cancelled"), any status not explicitly exempted was
    // payment-gated — so adding a state to an order enum silently made it
    // gated, failing closed in the one direction a clinical system must not.
    assertGate(gatePolicy(authorized: false), 'awaiting_specimen_recollection');

    expect(true)->toBeTrue();
});

it('stands down entirely when the gate is switched off in configuration', function (): void {
    config()->set('revenue.prepaid_required_for.laboratory_order', false);

    assertGate(gatePolicy(authorized: false), 'in_progress');

    expect(true)->toBeTrue();
});

it('derives the gate switch from the charge kind, so a new kind cannot be silently unreadable', function (): void {
    config()->set('revenue.prepaid_required_for.laboratory_order', false);
    config()->set('revenue.prepaid_required_for.radiology_order', true);

    expect(ChargeSourceKind::LABORATORY_ORDER->prepaidGateEnabled())->toBeFalse()
        ->and(ChargeSourceKind::RADIOLOGY_ORDER->prepaidGateEnabled())->toBeTrue();
});
