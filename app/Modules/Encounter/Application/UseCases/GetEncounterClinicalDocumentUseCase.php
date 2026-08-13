<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Encounter\Domain\Repositories\EncounterClinicalDocumentRepositoryInterface;

class GetEncounterClinicalDocumentUseCase
{
    public function __construct(
        private readonly EncounterResolverService $encounterResolverService,
        private readonly EncounterClinicalDocumentRepositoryInterface $documentRepository,
    ) {}

    public function execute(string $encounterId, string $documentId): ?array
    {
        $encounter = $this->encounterResolverService->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        return $this->documentRepository->findByIdForEncounter(
            encounterId: $encounterId,
            id: $documentId,
        );
    }
}
