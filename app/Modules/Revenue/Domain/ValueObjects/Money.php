<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * An exact amount of money, held as an integer count of minor units.
 *
 * The retired billing engine resolved prices as PHP floats and rounded with
 * round($x, 2). That is tolerable for a catalogue lookup and wrong for a
 * ledger: a charge, its allocations and a day's takings must sum to the same
 * number every time, and binary floating point does not promise that. Every
 * amount in the revenue ledger is therefore an integer, and arithmetic on it
 * is exact by construction.
 *
 * Currency is carried alongside, and mixing two currencies throws rather than
 * silently producing a meaningless total. TZS has no circulating subunit, but
 * amounts are still stored in minor units so the same code serves a facility
 * billing in a currency that does.
 */
final readonly class Money
{
    private function __construct(
        public int $minorUnits,
        public string $currencyCode,
    ) {}

    public static function of(int $minorUnits, string $currencyCode): self
    {
        $normalized = strtoupper(trim($currencyCode));

        if (! preg_match('/^[A-Z]{3}$/', $normalized)) {
            throw new InvalidArgumentException(
                sprintf('Currency code must be three letters, got "%s".', $currencyCode),
            );
        }

        return new self($minorUnits, $normalized);
    }

    public static function zero(string $currencyCode): self
    {
        return self::of(0, $currencyCode);
    }

    /**
     * Convert a decimal amount — a price-book unit_price, or a figure typed at
     * the counter — into minor units.
     *
     * Accepts a string in preference to a float wherever the caller has one,
     * because "15000.10" survives the trip and 15000.10 may not. Values are
     * rounded half-up at the boundary; this is the only place in the ledger
     * where rounding happens at all.
     */
    public static function fromDecimal(string|int|float $amount, string $currencyCode, int $scale = 2): self
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Scale cannot be negative.');
        }

        $asString = is_float($amount)
            ? number_format($amount, $scale + 1, '.', '')
            : (string) $amount;

        if (! preg_match('/^-?\d+(\.\d+)?$/', trim($asString))) {
            throw new InvalidArgumentException(sprintf('Unparseable money amount "%s".', $asString));
        }

        $negative = str_starts_with(trim($asString), '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim(trim($asString), '-'), 2), 2, '');

        // Pad or round the fractional part to exactly $scale digits, half-up.
        $fraction = str_pad($fraction, $scale + 1, '0');
        $keep = substr($fraction, 0, $scale);
        $nextDigit = (int) $fraction[$scale];

        $minor = (int) $whole * (10 ** $scale) + (int) ($keep === '' ? '0' : $keep);
        if ($nextDigit >= 5) {
            $minor++;
        }

        return self::of($negative ? -$minor : $minor, $currencyCode);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currencyCode);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currencyCode);
    }

    /**
     * Multiply by a quantity, rounding half-up to the nearest minor unit.
     *
     * Quantities are genuinely fractional in this domain — 0.5 of a vial,
     * 2.5 hours of a bed — so this cannot be integer multiplication.
     */
    public function multipliedBy(float $factor): self
    {
        return new self((int) round($this->minorUnits * $factor, 0, PHP_ROUND_HALF_UP), $this->currencyCode);
    }

    /**
     * Take a percentage of this amount, rounding half-up.
     */
    public function percentage(float $percent): self
    {
        return $this->multipliedBy($percent / 100);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function equals(self $other): bool
    {
        return $this->currencyCode === $other->currencyCode
            && $this->minorUnits === $other->minorUnits;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits >= $other->minorUnits;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    /**
     * Decimal string form, for display and for writing to a numeric column.
     * Never returns scientific notation, which is the failure mode that makes
     * float-formatted money unreadable in a receipt.
     */
    public function toDecimalString(int $scale = 2): string
    {
        $negative = $this->minorUnits < 0;
        $abs = abs($this->minorUnits);
        $divisor = 10 ** $scale;

        $whole = intdiv($abs, $divisor);
        $fraction = $abs % $divisor;

        $formatted = $scale === 0
            ? (string) $whole
            : $whole.'.'.str_pad((string) $fraction, $scale, '0', STR_PAD_LEFT);

        return $negative ? '-'.$formatted : $formatted;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currencyCode !== $other->currencyCode) {
            throw new InvalidArgumentException(sprintf(
                'Cannot combine %s with %s — money of different currencies has no meaningful sum.',
                $this->currencyCode,
                $other->currencyCode,
            ));
        }
    }
}
