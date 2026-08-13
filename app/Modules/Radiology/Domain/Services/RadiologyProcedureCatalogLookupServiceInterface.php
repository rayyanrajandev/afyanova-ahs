<?php

namespace App\Modules\Radiology\Domain\Services;

interface RadiologyProcedureCatalogLookupServiceInterface
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
