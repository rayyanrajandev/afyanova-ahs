<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Encounter\Application\Services\EncounterLifecycleService;

class GetEncounterUseCase
{
    public function __construct(
        private readonly EncounterLifecycleService $encounterLifecycleService,
    ) {}

    public function execute(string $id): ?array
    {
        $encounter = $this->encounterLifecycleService->findById($id);

        return $encounter?->toArray();
    }
}
