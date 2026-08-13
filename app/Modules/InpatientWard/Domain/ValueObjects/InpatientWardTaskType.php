<?php

namespace App\Modules\InpatientWard\Domain\ValueObjects;

enum InpatientWardTaskType: string
{
    case MEDICATION = 'medication';
    case VITALS = 'vitals';
    case LAB_FOLLOW_UP = 'lab_follow_up';
    case IMAGING_FOLLOW_UP = 'imaging_follow_up';
    case WOUND_CARE = 'wound_care';
    case PHYSIOTHERAPY = 'physiotherapy';
    case NURSING_REVIEW = 'nursing_review';
    case DISCHARGE_PREP = 'discharge_prep';
    case OTHER = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
