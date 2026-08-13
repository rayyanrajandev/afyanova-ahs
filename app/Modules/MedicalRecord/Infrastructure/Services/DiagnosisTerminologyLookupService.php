<?php

namespace App\Modules\MedicalRecord\Infrastructure\Services;

use App\Modules\MedicalRecord\Domain\Services\DiagnosisTerminologyLookupServiceInterface;
use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;

class DiagnosisTerminologyLookupService implements DiagnosisTerminologyLookupServiceInterface
{
    public function __construct(
        private readonly ClinicalCatalogItemRepositoryInterface $clinicalCatalogItemRepository,
    ) {}

    public function hasAnyActiveDiagnosisCodes(): bool
    {
        $counts = $this->clinicalCatalogItemRepository->statusCounts(
            catalogType: ClinicalCatalogType::DIAGNOSIS_CODE->value,
            query: null,
            departmentId: null,
            category: null,
        );

        return (int) ($counts['active'] ?? 0) > 0;
    }

    public function isActiveDiagnosisCode(string $diagnosisCode): bool
    {
        $normalized = strtoupper(trim($diagnosisCode));
        if ($normalized === '') {
            return false;
        }

        $result = $this->clinicalCatalogItemRepository->search(
            catalogType: ClinicalCatalogType::DIAGNOSIS_CODE->value,
            query: $normalized,
            status: 'active',
            departmentId: null,
            category: null,
            page: 1,
            perPage: 100,
            sortBy: 'code',
            sortDirection: 'asc',
        );

        foreach ($result['data'] ?? [] as $item) {
            $itemCode = strtoupper(trim((string) ($item['code'] ?? '')));
            if ($itemCode === $normalized) {
                return true;
            }
        }

        return false;
    }
}
