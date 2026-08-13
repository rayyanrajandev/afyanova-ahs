<?php

namespace App\Modules\Staff\Domain\Repositories;

interface StaffRegulatoryProfileRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function findByStaffProfileId(string $staffProfileId): ?array;

    /**
     * @param  array<int, string>  $staffProfileIds
     * @return array<string, array<string, mixed>>
     */
    public function findByStaffProfileIds(array $staffProfileIds): array;

    public function update(string $id, array $attributes): ?array;
}
