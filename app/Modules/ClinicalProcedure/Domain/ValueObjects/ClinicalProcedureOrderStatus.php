<?php

namespace App\Modules\ClinicalProcedure\Domain\ValueObjects;

enum ClinicalProcedureOrderStatus: string
{
    case ORDERED = 'ordered';
    case SCHEDULED = 'scheduled';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public static function openWorklistValues(): array
    {
        return [
            self::ORDERED->value,
            self::SCHEDULED->value,
            self::IN_PROGRESS->value,
        ];
    }

    public static function terminalValues(): array
    {
        return [self::COMPLETED->value, self::CANCELLED->value];
    }

    public static function allowedForwardTransitions(string $currentStatus): array
    {
        return match ($currentStatus) {
            self::ORDERED->value => [self::SCHEDULED->value, self::CANCELLED->value],
            self::SCHEDULED->value => [self::IN_PROGRESS->value, self::CANCELLED->value],
            self::IN_PROGRESS->value => [self::COMPLETED->value, self::CANCELLED->value],
            self::COMPLETED->value, self::CANCELLED->value => [],
            default => [],
        };
    }

    public static function canTransitionForward(string $currentStatus, string $nextStatus): bool
    {
        return in_array($nextStatus, self::allowedForwardTransitions($currentStatus), true);
    }
}
