<?php

namespace App\Modules\Revenue\Application\Services;

use App\Modules\Revenue\Domain\Events\CashierQueueUpdated;
use Illuminate\Support\Facades\DB;

/**
 * Announces that the cashier queue has moved — once per request, after commit.
 *
 * Driven by writes to the tables the queue is derived from rather than by a
 * call in each use case. Six use cases already change it (raise, pay, reverse,
 * waive, cancel, refund) and more will; a list of call sites is a list of
 * places to forget one, and a queue that silently stops updating after some
 * actions is worse than one that never updated at all.
 *
 * Deduplicated per transaction, because a single counter transaction touches
 * several rows — the charge, the payment, its allocations, the receipt — and
 * each would otherwise be its own broadcast. Every listener does the same thing
 * with any of them: refetch.
 *
 * The transaction is the unit of deduplication rather than the request, and
 * that is deliberate: a write outside a transaction is its own unit of work and
 * genuinely is a separate change, so it announces separately. Suppressing it
 * would mean a second change in one request silently never reached the other
 * tills.
 *
 * After commit, so a rolled-back payment never tells another till that
 * something changed.
 */
class CashierQueueAnnouncer
{
    /**
     * @var array<string, true>
     */
    private array $pending = [];

    public function markDirty(?string $facilityId): void
    {
        if ($facilityId === null || isset($this->pending[$facilityId])) {
            return;
        }

        $this->pending[$facilityId] = true;

        DB::afterCommit(function () use ($facilityId): void {
            unset($this->pending[$facilityId]);

            event(new CashierQueueUpdated($facilityId));
        });
    }
}
