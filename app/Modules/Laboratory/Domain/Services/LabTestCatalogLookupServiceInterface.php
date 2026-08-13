<?php

namespace App\Modules\Laboratory\Domain\Services;

interface LabTestCatalogLookupServiceInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findActiveById(string $id): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByCode(string $code): ?array;
}
