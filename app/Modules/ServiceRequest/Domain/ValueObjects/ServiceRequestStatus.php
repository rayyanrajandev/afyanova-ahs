<?php

namespace App\Modules\ServiceRequest\Domain\ValueObjects;

enum ServiceRequestStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * @return array<string, string[]>
     */
    public static function allowedForwardTransitions(): array
    {
        return [
            self::PENDING->value => [self::IN_PROGRESS->value, self::CANCELLED->value],
            self::IN_PROGRESS->value => [self::COMPLETED->value, self::CANCELLED->value],
            self::COMPLETED->value => [],
            self::CANCELLED->value => [],
        ];
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::allowedForwardTransitions()[$this->value] ?? [], true);
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
