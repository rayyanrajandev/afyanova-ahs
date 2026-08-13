<?php

use App\Modules\Billing\Domain\Services\DurationChargeStrategy;

it('charges a flat quantity of 1 regardless of duration', function (): void {
    $strategy = new DurationChargeStrategy();

    expect($strategy->resolveQuantity('flat', 0))->toBe(1.0)
        ->and($strategy->resolveQuantity('flat', 500))->toBe(1.0);
});

it('passes per_unit quantity through unchanged, clamped at zero', function (): void {
    $strategy = new DurationChargeStrategy();

    expect($strategy->resolveQuantity('per_unit', 3.5))->toBe(3.5)
        ->and($strategy->resolveQuantity('per_unit', -2))->toBe(0.0);
});

it('charges per_day as any part of a day counts as a full day, minimum 1', function (): void {
    $strategy = new DurationChargeStrategy();

    expect($strategy->quantityForPerDay(0))->toBe(1.0)
        ->and($strategy->quantityForPerDay(1))->toBe(1.0)
        ->and($strategy->quantityForPerDay(23.99))->toBe(1.0)
        ->and($strategy->quantityForPerDay(24))->toBe(1.0)
        ->and($strategy->quantityForPerDay(24.01))->toBe(2.0)
        ->and($strategy->quantityForPerDay(48))->toBe(2.0)
        ->and($strategy->quantityForPerDay(48.5))->toBe(3.0)
        ->and($strategy->quantityForPerDay(-5))->toBe(1.0);
});

it('charges per_hour as any part of an hour counts as a full hour, minimum 1', function (): void {
    $strategy = new DurationChargeStrategy();

    expect($strategy->quantityForPerHour(0))->toBe(1.0)
        ->and($strategy->quantityForPerHour(0.5))->toBe(1.0)
        ->and($strategy->quantityForPerHour(1))->toBe(1.0)
        ->and($strategy->quantityForPerHour(1.01))->toBe(2.0)
        ->and($strategy->quantityForPerHour(-3))->toBe(1.0);
});

it('resolveQuantity dispatches to the correct strategy per charge model', function (): void {
    $strategy = new DurationChargeStrategy();

    expect($strategy->resolveQuantity('per_day', 25))->toBe(2.0)
        ->and($strategy->resolveQuantity('per_hour', 1.5))->toBe(2.0)
        ->and($strategy->resolveQuantity('unknown_model', 7))->toBe(7.0);
});
