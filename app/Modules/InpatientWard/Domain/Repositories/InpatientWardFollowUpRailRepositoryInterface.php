<?php

namespace App\Modules\InpatientWard\Domain\Repositories;

interface InpatientWardFollowUpRailRepositoryInterface
{
    public function summarizeForAdmission(string $admissionId, int $itemLimit = 3): array;
}
