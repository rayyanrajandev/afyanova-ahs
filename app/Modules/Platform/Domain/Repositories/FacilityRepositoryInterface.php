<?php

namespace App\Modules\Platform\Domain\Repositories;

interface FacilityRepositoryInterface
{
    public function findByCode(string $code, ?string $tenantId = null): ?array;

    public function findById(string $id): ?array;
}
