<?php

namespace App\Modules\Revenue\Domain\Services;

use App\Modules\Revenue\Domain\ValueObjects\Money;

interface RevenueAuditRecorderInterface
{
    /**
     * Append one financial audit event.
     *
     * Called inside the same transaction as the write it describes, so an
     * event can never survive a rolled-back payment and a committed payment
     * can never be missing its event.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $entityType,
        string $entityId,
        string $action,
        ?int $actorUserId = null,
        ?Money $amount = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $cashierSessionId = null,
    ): void;
}
