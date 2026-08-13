<?php

namespace App\Modules\Pharmacy\Domain\Services;

interface ApprovedMedicineCatalogLookupServiceInterface
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