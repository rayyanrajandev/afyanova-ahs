<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;

class ListWalkInDepartmentOptionsUseCase
{
    public function __construct(
        private readonly DepartmentRepositoryInterface $departmentRepository,
    ) {}

    /**
     * Active departments in scope, for directing direct-service patients (option {@see value} is department UUID).
     *
     * @return array<int, array{value: string, label: string, code: string|null, serviceType: string|null}>
     */
    public function execute(?string $serviceType = null): array
    {
        $result = $this->departmentRepository->search(
            query: null,
            status: 'active',
            serviceType: null,
            managerUserId: null,
            page: 1,
            perPage: 500,
            sortBy: 'name',
            sortDirection: 'asc',
        );

        $options = [];
        foreach ($result['data'] ?? [] as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }

            $code = trim((string) ($row['code'] ?? ''));
            $departmentServiceType = isset($row['service_type']) ? trim((string) $row['service_type']) : '';

            if (! $this->matchesServiceDesk($row, $serviceType)) {
                continue;
            }

            $options[] = [
                'value' => $id,
                'label' => $code !== '' ? sprintf('%s - %s', $code, $name) : $name,
                'code' => $code !== '' ? $code : null,
                'serviceType' => $departmentServiceType !== '' ? $departmentServiceType : null,
            ];
        }

        return $options;
    }

    /**
     * Department service categories are intentionally broader than the direct-service desks,
     * so match against code, name, and category text.
     *
     * @param  array<string, mixed>  $department
     */
    private function matchesServiceDesk(array $department, ?string $serviceType): bool
    {
        $serviceType = trim((string) $serviceType);
        if ($serviceType === '' || $serviceType === 'all') {
            return true;
        }

        $haystack = strtolower(implode(' ', array_filter([
            $department['code'] ?? null,
            $department['name'] ?? null,
            $department['service_type'] ?? null,
        ], static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')));

        $keywordsByDesk = [
            'laboratory' => ['lab', 'laboratory', 'pathology', 'sample'],
            'pharmacy' => ['pharmacy', 'dispensary', 'dispensing', 'medicine'],
            'radiology' => ['radiology', 'imaging', 'x-ray', 'xray', 'ultrasound', 'scan'],
            'theatre_procedure' => ['theatre', 'procedure', 'surgery', 'surgical', 'operating'],
        ];

        foreach ($keywordsByDesk[$serviceType] ?? [] as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
