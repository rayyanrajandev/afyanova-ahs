<?php

namespace App\Modules\ClaimsInsurance\Domain\Repositories;

interface ClaimsInsuranceCaseRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByClaimNumber(string $claimNumber): bool;

    public function existsActiveForInvoice(string $invoiceId, ?string $excludeId = null): bool;

    public function search(
        ?string $query,
        ?string $invoiceId,
        ?string $patientId,
        ?string $status,
        ?string $reconciliationStatus,
        ?string $reconciliationExceptionStatus,
        ?string $payerType,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $invoiceId,
        ?string $patientId,
        ?string $reconciliationStatus,
        ?string $reconciliationExceptionStatus,
        ?string $payerType,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;
}
