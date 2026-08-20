<?php

namespace App\Modules\ClinicalProcedure\Infrastructure\Observers;

use App\Modules\ClinicalProcedure\Application\Services\ClinicalProcedureQueueAnnouncer;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;

class ClinicalProcedureOrderObserver
{
    public function __construct(private readonly ClinicalProcedureQueueAnnouncer $announcer) {}

    public function created(ClinicalProcedureOrderModel $order): void
    {
        $this->announcer->markDirty($order->facility_id);
    }

    public function updated(ClinicalProcedureOrderModel $order): void
    {
        $this->announcer->markDirty($order->facility_id);
    }

    public function deleted(ClinicalProcedureOrderModel $order): void
    {
        $this->announcer->markDirty($order->facility_id);
    }
}
