<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;

class ListStaffDepartmentOptionsUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly DepartmentRepositoryInterface $departmentRepository,
    ) {}

    /**
     * @return array<int, array{value:string,label:string,group?:string|null,description?:string|null,keywords?:array<int,string>}>
     */
    public function execute(): array
    {
        $options = [];
        $seen = [];

        $departments = $this->departmentRepository->search(
            query: null,
            status: 'active',
            serviceType: null,
            managerUserId: null,
            page: 1,
            perPage: 100,
            sortBy: 'name',
            sortDirection: 'asc',
        );

        foreach (($departments['data'] ?? []) as $department) {
            $name = trim((string) ($department['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }

            $code = trim((string) ($department['code'] ?? ''));
            $serviceType = trim((string) ($department['service_type'] ?? ''));
            $options[] = [
                'id' => $department['id'] ?? null,
                'value' => $department['id'] ?? $name,
                'label' => $code !== '' ? sprintf('%s - %s', $code, $name) : $name,
                'group' => $serviceType !== '' ? $serviceType : null,
                'description' => $serviceType !== '' ? sprintf('Category: %s', $serviceType) : null,
                'keywords' => array_values(array_filter([$code, $serviceType])),
            ];
            $seen[$key] = true;
        }

        foreach ($this->staffProfileRepository->listDistinctDepartments() as $department) {
            $value = trim($department);
            if ($value === '') {
                continue;
            }

            $key = mb_strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }

            $options[] = [
                'id' => null,
                'value' => $value,
                'label' => $value,
                'group' => 'Legacy / uncategorized',
                'description' => 'Existing staff department value not yet linked to the department registry.',
                'keywords' => ['legacy'],
            ];
            $seen[$key] = true;
        }

        return $options;
    }
}
