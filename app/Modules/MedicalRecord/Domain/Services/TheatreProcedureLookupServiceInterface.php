<?php

namespace App\Modules\MedicalRecord\Domain\Services;

interface TheatreProcedureLookupServiceInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $theatreProcedureId): ?array;
}
