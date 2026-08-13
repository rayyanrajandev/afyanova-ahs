<?php

namespace App\Modules\TheatreProcedure\Domain\ValueObjects;

enum TheatreProcedureStatus: string
{
    case PLANNED = 'planned';
    case IN_PREOP = 'in_preop';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * @return array<int, string>
     */
    public static function terminalValues(): array
    {
        return [self::COMPLETED->value, self::CANCELLED->value];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedForwardTransitions(string $currentStatus): array
    {
        return match ($currentStatus) {
            self::PLANNED->value => [self::IN_PREOP->value, self::CANCELLED->value],
            self::IN_PREOP->value => [self::IN_PROGRESS->value, self::CANCELLED->value],
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
