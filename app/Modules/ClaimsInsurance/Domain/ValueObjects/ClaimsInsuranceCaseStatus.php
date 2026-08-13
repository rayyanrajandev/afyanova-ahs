<?php

namespace App\Modules\ClaimsInsurance\Domain\ValueObjects;

enum ClaimsInsuranceCaseStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case ADJUDICATING = 'adjudicating';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case PARTIAL = 'partial';
    case CANCELLED = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
