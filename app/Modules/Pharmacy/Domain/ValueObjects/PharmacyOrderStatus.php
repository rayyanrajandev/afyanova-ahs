<?php

namespace App\Modules\Pharmacy\Domain\ValueObjects;

enum PharmacyOrderStatus: string
{
    case PENDING = 'pending';
    case IN_PREPARATION = 'in_preparation';
    case PARTIALLY_DISPENSED = 'partially_dispensed';
    case DISPENSED = 'dispensed';
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
    public static function openWorklistValues(): array
    {
        return [
            self::PENDING->value,
            self::IN_PREPARATION->value,
            self::PARTIALLY_DISPENSED->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function terminalValues(): array
    {
        return [self::DISPENSED->value, self::CANCELLED->value];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedWorkflowTransitions(string $currentStatus): array
    {
        return match ($currentStatus) {
            self::PENDING->value => [
                self::IN_PREPARATION->value,
                self::CANCELLED->value,
            ],
            self::IN_PREPARATION->value => [
                self::PARTIALLY_DISPENSED->value,
                self::DISPENSED->value,
                self::CANCELLED->value,
            ],
            self::PARTIALLY_DISPENSED->value => [
                self::PARTIALLY_DISPENSED->value,
                self::DISPENSED->value,
                self::CANCELLED->value,
            ],
            self::DISPENSED->value,
            self::CANCELLED->value => [],
            default => [],
        };
    }

    public static function canTransitionWorkflow(string $currentStatus, string $nextStatus): bool
    {
        return in_array($nextStatus, self::allowedWorkflowTransitions($currentStatus), true);
    }
}
