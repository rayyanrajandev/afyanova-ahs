<?php

namespace App\Modules\Staff\Domain\ValueObjects;

enum StaffCredentialingState: string
{
    case READY = 'ready';
    case WATCH = 'watch';
    case BLOCKED = 'blocked';
    case PENDING_VERIFICATION = 'pending_verification';
    case NOT_REQUIRED = 'not_required';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
