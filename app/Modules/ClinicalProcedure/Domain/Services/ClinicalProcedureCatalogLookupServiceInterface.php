<?php

namespace App\Modules\ClinicalProcedure\Domain\Services;

interface ClinicalProcedureCatalogLookupServiceInterface
{
    public function findActiveById(string $id): ?array;

    public function findActiveByCode(string $code): ?array;
}
