<?php

namespace App\Modules\ClinicalProcedure\Application\Services;

use App\Modules\ClinicalProcedure\Domain\Events\ClinicalProcedureQueueUpdated;
use Illuminate\Support\Facades\DB;

class ClinicalProcedureQueueAnnouncer
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

            event(new ClinicalProcedureQueueUpdated($facilityId));
        });
    }
}
