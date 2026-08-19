<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

use App\Support\FinancialCoverage;

/**
 * Who is expected to pay for a charge.
 *
 * Mirrors App\Support\FinancialCoverage — the enum appointments and admissions
 * already carry in `financial_coverage_type` — so a charge inherits the payer
 * decision made at registration instead of inventing a second vocabulary for
 * it.
 *
 * Only SELF_PAY is implemented. The others exist because the ledger is
 * payer-aware by design: adding an insurer later means registering an
 * authorization policy for its class, not migrating this column.
 */
enum PayerClass: string
{
    case SELF_PAY = 'self_pay';
    case INSURANCE = 'insurance';
    case EMPLOYER = 'employer';
    case GOVERNMENT = 'government';
    case DONOR = 'donor';
    case OTHER = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function fromFinancialCoverage(?string $coverageType): self
    {
        return self::tryFrom((string) FinancialCoverage::normalize($coverageType)) ?? self::SELF_PAY;
    }

    /**
     * Whether this phase can actually settle a charge for this payer.
     *
     * Everything except self-pay resolves to false: there is no coverage
     * calculator, no eligibility check and no settlement path, so a charge
     * raised against one would sit unauthorized forever. Callers use this to
     * fail loudly at the point the payer is chosen rather than silently
     * stranding a patient at the counter.
     */
    public function isImplemented(): bool
    {
        return $this === self::SELF_PAY;
    }

    public function requiresPayerContract(): bool
    {
        return $this !== self::SELF_PAY;
    }
}
