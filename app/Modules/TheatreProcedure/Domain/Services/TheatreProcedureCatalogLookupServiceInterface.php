<?php

namespace App\Modules\TheatreProcedure\Domain\Services;

interface TheatreProcedureCatalogLookupServiceInterface
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
