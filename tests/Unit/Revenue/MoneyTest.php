<?php

use App\Modules\Revenue\Domain\ValueObjects\Money;

it('adds and subtracts exactly', function (): void {
    $a = Money::of(1500000, 'TZS');
    $b = Money::of(250050, 'TZS');

    expect($a->plus($b)->minorUnits)->toBe(1750050)
        ->and($a->minus($b)->minorUnits)->toBe(1249950);
});

it('refuses to combine different currencies', function (): void {
    Money::of(100, 'TZS')->plus(Money::of(100, 'USD'));
})->throws(InvalidArgumentException::class, 'different currencies');

it('rejects a malformed currency code', function (): void {
    Money::of(100, 'TZSX');
})->throws(InvalidArgumentException::class);

it('parses decimal strings without floating point drift', function (
    string $input,
    int $expected,
): void {
    expect(Money::fromDecimal($input, 'TZS')->minorUnits)->toBe($expected);
})->with([
    ['15000.00', 1500000],
    ['15000', 1500000],
    ['15000.5', 1500050],
    ['0.01', 1],
    ['0.005', 1],      // half-up
    ['0.004', 0],      // below half
    ['0.999', 100],
    ['-250.25', -25025],
]);

it('survives the classic float representation traps', function (): void {
    // 0.1 + 0.2 !== 0.3 in binary floating point. In minor units it does.
    $sum = Money::fromDecimal('0.10', 'USD')->plus(Money::fromDecimal('0.20', 'USD'));

    expect($sum->minorUnits)->toBe(30)
        ->and($sum->equals(Money::fromDecimal('0.30', 'USD')))->toBeTrue();
});

it('multiplies by a fractional quantity, rounding half up', function (): void {
    $unit = Money::fromDecimal('15000.00', 'TZS');

    expect($unit->multipliedBy(2.0)->minorUnits)->toBe(3000000)
        ->and($unit->multipliedBy(0.5)->minorUnits)->toBe(750000)
        ->and(Money::of(5, 'TZS')->multipliedBy(0.5)->minorUnits)->toBe(3);
});

it('takes a percentage the way the review discount needs', function (): void {
    $gross = Money::fromDecimal('15000.00', 'TZS');

    expect($gross->percentage(50.0)->minorUnits)->toBe(750000)
        ->and($gross->percentage(0.0)->isZero())->toBeTrue();
});

it('formats as a decimal string without scientific notation', function (): void {
    expect(Money::of(1500000, 'TZS')->toDecimalString())->toBe('15000.00')
        ->and(Money::of(5, 'TZS')->toDecimalString())->toBe('0.05')
        ->and(Money::of(-25025, 'TZS')->toDecimalString())->toBe('-250.25')
        ->and(Money::of(100000000000, 'TZS')->toDecimalString())->toBe('1000000000.00');
});

it('round-trips through decimal string and back', function (int $minor): void {
    $money = Money::of($minor, 'TZS');

    expect(Money::fromDecimal($money->toDecimalString(), 'TZS')->minorUnits)->toBe($minor);
})->with([0, 1, 99, 100, 1500000, 999999999, -4242]);

it('compares amounts', function (): void {
    $small = Money::of(100, 'TZS');
    $large = Money::of(200, 'TZS');

    expect($large->isGreaterThan($small))->toBeTrue()
        ->and($small->isLessThan($large))->toBeTrue()
        ->and($small->isGreaterThanOrEqualTo($small))->toBeTrue()
        ->and(Money::zero('TZS')->isZero())->toBeTrue()
        ->and(Money::of(-1, 'TZS')->isNegative())->toBeTrue();
});
