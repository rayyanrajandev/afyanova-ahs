<?php

namespace App\Modules\Laboratory\Application\Services;

use App\Modules\Laboratory\Domain\Events\LaboratoryQueueUpdated;
use Illuminate\Support\Facades\DB;

class LaboratoryQueueAnnouncer
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

            event(new LaboratoryQueueUpdated($facilityId));
        });
    }
}
