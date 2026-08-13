<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Encounter\Domain\Repositories\EncounterClinicalDocumentRepositoryInterface;
use App\Modules\Encounter\Domain\ValueObjects\EncounterClinicalDocumentStatus;

class ListEncounterClinicalDocumentsUseCase
{
    public function __construct(
        private readonly EncounterResolverService $encounterResolverService,
        private readonly EncounterClinicalDocumentRepositoryInterface $documentRepository,
    ) {}

    public function execute(string $encounterId, array $filters): ?array
    {
        $encounter = $this->encounterResolverService->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $documentType = isset($filters['documentType']) ? trim((string) $filters['documentType']) : null;
        $documentType = $documentType === '' ? null : $documentType;

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, EncounterClinicalDocumentStatus::values(), true)) {
            $status = null;
        }

        $sortMap = [
            'title' => 'title',
            'documentType' => 'document_type',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'createdAt'] ?? 'created_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        return $this->documentRepository->searchByEncounterId(
            encounterId: $encounterId,
            query: $query,
            documentType: $documentType,
            status: $status,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
