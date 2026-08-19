<?php

namespace App\Modules\Revenue\Infrastructure\Observers;

use App\Modules\Revenue\Application\Services\CashierQueueAnnouncer;
use App\Modules\Revenue\Infrastructure\Models\PaymentModel;

/**
 * A payment moves a patient off the awaiting list and onto paid-today, and a
 * reversal moves them back. Both are the queue changing.
 */
class PaymentObserver
{
    public function __construct(private readonly CashierQueueAnnouncer $announcer) {}

    public function created(PaymentModel $payment): void
    {
        $this->announcer->markDirty($payment->facility_id);
    }

    public function updated(PaymentModel $payment): void
    {
        $this->announcer->markDirty($payment->facility_id);
    }
}
