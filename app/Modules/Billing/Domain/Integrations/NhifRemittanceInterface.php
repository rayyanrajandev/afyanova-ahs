<?php

namespace App\Modules\Billing\Domain\Integrations;

use App\Modules\Billing\Domain\ValueObjects\NhifRemittanceResult;

interface NhifRemittanceInterface
{
    public function parseFile(string $filePath, string $format = 'csv'): array;

    public function reconcile(array $remittanceRecords, string $tenantId, string $facilityId): NhifRemittanceResult;

    public function processFile(
        string $filePath,
        string $tenantId,
        string $facilityId,
        string $format = 'csv',
        ?string $originalFilename = null,
        ?int $userId = null,
    ): NhifRemittanceResult;
}
