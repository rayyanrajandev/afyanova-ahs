<?php

namespace App\Modules\Platform\Domain\Repositories;

interface CountryProfileRepositoryInterface
{
    public function getActiveCode(): string;

    public function findByCode(string $code): ?array;

    public function all(): array;
}
