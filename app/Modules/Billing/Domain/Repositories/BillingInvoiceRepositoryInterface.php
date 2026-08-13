<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingInvoiceRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function findByInvoiceNumber(string $invoiceNumber): ?array;

    public function findMatchingDraft(
        string $patientId,
        ?string $appointmentId,
        ?string $admissionId,
        ?string $billingPayerContractId,
        string $currencyCode,
        ?string $encounterId = null,
    ): ?array;

    public function findByLineItemSource(
        string $patientId,
        string $sourceWorkflowKind,
        string $sourceWorkflowId,
        ?string $excludeInvoiceId = null,
    ): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByInvoiceNumber(string $invoiceNumber): bool;

    public function search(
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?array $statuses,
        ?string $currencyCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?string $paymentActivityFromDateTime,
        ?string $paymentActivityToDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $patientId,
        ?string $currencyCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?string $paymentActivityFromDateTime,
        ?string $paymentActivityToDateTime
    ): array;

    public function billingDepartmentOptions(): array;

    public function financialControlSummary(
        ?string $currencyCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?string $payerType,
        ?string $asOfDateTime,
        ?string $departmentFilter
    ): array;

    public function revenueRecognitionSummary(
        ?string $currencyCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?string $asOfDateTime,
        ?string $departmentFilter
    ): array;
}
