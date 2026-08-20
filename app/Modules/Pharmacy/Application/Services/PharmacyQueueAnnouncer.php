<?php

namespace App\Modules\Pharmacy\Application\Services;

use App\Modules\Pharmacy\Domain\Events\PharmacyQueueUpdated;
use Illuminate\Support\Facades\DB;

class PharmacyQueueAnnouncer
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

            event(new PharmacyQueueUpdated($facilityId));
        });
    }
}
