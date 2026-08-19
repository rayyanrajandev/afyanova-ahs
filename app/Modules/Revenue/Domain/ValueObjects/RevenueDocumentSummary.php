<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

/**
 * A financial document reduced to what a clinical surface needs in order to
 * show it in a feed or a follow-up rail: an identity, a status, and a time.
 *
 * Deliberately says nothing about *which* document it is. Today the prepaid
 * ledger does not exist yet and nothing produces one of these; from Phase 4 it
 * will be a receipt or an outstanding charge. Consumers must not care, which
 * is the whole point of routing them through this type instead of an Eloquent
 * model they would then be coupled to.
 */
final readonly class RevenueDocumentSummary
{
    public function __construct(
        public string $id,
        public ?string $number,
        public string $title,
        public string $status,
        public ?string $occurredAt,
        public ?string $dueAt = null,
        public ?string $detail = null,
    ) {}
}
