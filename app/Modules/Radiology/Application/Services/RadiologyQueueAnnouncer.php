<?php

namespace App\Modules\Radiology\Application\Services;

use App\Modules\Radiology\Domain\Events\RadiologyQueueUpdated;
use Illuminate\Support\Facades\DB;

class RadiologyQueueAnnouncer
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

            event(new RadiologyQueueUpdated($facilityId));
        });
    }
}
