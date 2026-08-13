<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Application\Support\ClinicalCatalogBulkCsvSchema;
use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;

class ExportClinicalCatalogItemsCsvUseCase
{
    public function __construct(
        private readonly ClinicalCatalogItemRepositoryInterface $repository,
    ) {}

    /**
     * @return array{columns: array<int, string>, rows: array<int, array<string, string>>}
     */
    public function execute(string $catalogType, array $filters): array
    {
        $columns = ClinicalCatalogBulkCsvSchema::columnsForCatalogType($catalogType);
        $departmentCodes = $this->departmentCodeMap();
        $rows = [];
        $page = 1;

        do {
            $result = $this->repository->search(
                catalogType: $catalogType,
                query: $this->nullableFilter($filters['q'] ?? null),
                status: $this->normalizedStatus($filters['status'] ?? null),
                departmentId: $this->nullableFilter($filters['departmentId'] ?? null),
                category: $this->nullableFilter($filters['category'] ?? null),
                dosageForm: $this->nullableFilter($filters['dosageForm'] ?? null),
                page: $page,
                perPage: 100,
                sortBy: 'code',
                sortDirection: 'asc',
                ids: $this->normalizedIds($filters['itemIds'] ?? null),
            );

            foreach ($result['data'] ?? [] as $item) {
                $departmentId = (string) ($item['department_id'] ?? '');
                $rows[] = ClinicalCatalogBulkCsvSchema::itemToCsvRow(
                    $item,
                    $departmentCodes[$departmentId] ?? null,
                );
            }

            $page++;
            $lastPage = (int) ($result['meta']['lastPage'] ?? 1);
        } while ($page <= $lastPage);

        return [
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function departmentCodeMap(): array
    {
        return DepartmentModel::query()
            ->whereNotNull('code')
            ->get(['id', 'code'])
            ->mapWithKeys(static fn (DepartmentModel $department): array => [
                (string) $department->id => strtoupper(trim((string) $department->code)),
            ])
            ->all();
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizedIds(mixed $itemIds): ?array
    {
        if (! is_array($itemIds)) {
            return null;
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $itemIds,
        ), static fn (string $value): bool => $value !== '')));

        return $ids === [] ? null : $ids;
    }

    private function nullableFilter(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizedStatus(mixed $value): ?string
    {
        $status = strtolower(trim((string) $value));

        return in_array($status, ClinicalCatalogItemStatus::values(), true) ? $status : null;
    }
}
