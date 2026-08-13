<?php

namespace App\Modules\ClaimsInsurance\Application\UseCases;

use App\Modules\ClaimsInsurance\Application\Exceptions\InvoiceNotEligibleForClaimsInsuranceCaseException;
use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseAuditLogRepositoryInterface;
use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseRepositoryInterface;
use App\Modules\ClaimsInsurance\Domain\Services\BillingInvoiceLookupServiceInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Str;

class UpdateClaimsInsuranceCaseUseCase
{
    public function __construct(
        private readonly ClaimsInsuranceCaseAuditLogRepositoryInterface $auditLogRepository,
        private readonly ClaimsInsuranceCaseRepositoryInterface $claimsInsuranceCaseRepository,
        private readonly BillingInvoiceLookupServiceInterface $billingInvoiceLookupService,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->claimsInsuranceCaseRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $invoice = null;
        $requestedInvoiceId = null;
        if (array_key_exists('invoice_id', $payload)) {
            $invoiceId = $payload['invoice_id'];
            if (! is_string($invoiceId) || trim($invoiceId) === '') {
                throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                    'Claims insurance case requires a valid invoice reference.',
                );
            }

            $requestedInvoiceId = trim($invoiceId);
            $invoice = $this->billingInvoiceLookupService->findInvoiceById($requestedInvoiceId);
            if (! $invoice) {
                throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                    'Claims insurance case requires an existing billing invoice.',
                );
            }

            if ($this->claimsInsuranceCaseRepository->existsActiveForInvoice($requestedInvoiceId, $id)) {
                throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                    'This invoice already has another active claims insurance case.',
                );
            }

            $this->assertInvoiceEligibleForClaim($invoice, $payload);

            $patientId = $invoice['patient_id'] ?? null;
            if (! is_string($patientId) || trim($patientId) === '') {
                throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                    'Billing invoice is missing patient context for claim update.',
                );
            }

            $payload['patient_id'] = $patientId;
            $payload['appointment_id'] = $invoice['appointment_id'] ?? null;
            $payload['admission_id'] = $invoice['admission_id'] ?? null;
            $payload['payer_type'] = $this->resolvePayerType($invoice, $payload['payer_type'] ?? null);
            $payload['claim_amount'] = $this->resolveClaimAmount($invoice);
            $invoiceCurrency = strtoupper(trim((string) ($invoice['currency_code'] ?? '')));
            $payload['currency_code'] = $invoiceCurrency === ''
                ? $this->defaultCurrencyResolver->resolve()
                : Str::substr($invoiceCurrency, 0, 3);
            if (! isset($payload['payer_name']) || trim((string) $payload['payer_name']) === '') {
                $payload['payer_name'] = $this->resolvePayerName($invoice);
            }
            if (! isset($payload['payer_reference']) || trim((string) $payload['payer_reference']) === '') {
                $payload['payer_reference'] = $this->resolvePayerReference($invoice);
            }
            $payload['payer_plan_name'] ??= $this->payerSummary($invoice)['payerPlanName'] ?? null;
            $payload['claim_readiness'] = $this->invoiceClaimReadiness($invoice);
        } elseif (array_key_exists('submitted_at', $payload) && $payload['submitted_at'] !== null) {
            $existingInvoiceId = is_string($existing['invoice_id'] ?? null)
                ? trim((string) $existing['invoice_id'])
                : '';
            if ($existingInvoiceId === '') {
                throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                    'Claims insurance case requires an invoice before submission.',
                );
            }

            $invoice = $this->billingInvoiceLookupService->findInvoiceById($existingInvoiceId);
            if (! $invoice) {
                throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                    'Claims insurance case requires an existing billing invoice before submission.',
                );
            }

            if ($this->claimsInsuranceCaseRepository->existsActiveForInvoice($existingInvoiceId, $id)) {
                throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                    'This invoice already has another active claims insurance case.',
                );
            }

            $this->assertInvoiceEligibleForClaim($invoice, $payload);
        }

        $updated = $this->claimsInsuranceCaseRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                claimsInsuranceCaseId: $id,
                action: 'claims-insurance-case.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function resolveClaimAmount(array $invoice): float
    {
        $payerSummary = $this->payerSummary($invoice);
        $expectedPayerAmount = round(max((float) ($payerSummary['expectedPayerAmount'] ?? 0), 0), 2);

        if ($expectedPayerAmount > 0) {
            return $expectedPayerAmount;
        }

        return round(max((float) ($invoice['total_amount'] ?? 0), 0), 2);
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @param  array<string, mixed>  $payload
     */
    private function assertInvoiceEligibleForClaim(array $invoice, array $payload): void
    {
        $status = strtolower(trim((string) ($invoice['status'] ?? '')));
        if (! in_array($status, ['issued', 'partially_paid', 'paid'], true)) {
            throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                'Issue the billing invoice before updating the insurance claim.',
            );
        }

        $claimReadiness = $this->invoiceClaimReadiness($invoice);
        if (! (bool) ($claimReadiness['claimEligible'] ?? false)) {
            throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                'This invoice is self-pay or has no payer-sponsored balance for a claim.',
            );
        }

        if ($this->normalizeNullableText($payload['submitted_at'] ?? null) !== null
            && ! (bool) ($claimReadiness['ready'] ?? false)) {
            $blockingReasons = is_array($claimReadiness['blockingReasons'] ?? null)
                ? array_values(array_filter(
                    array_map(static fn (mixed $reason): string => trim((string) $reason), $claimReadiness['blockingReasons']),
                    static fn (string $reason): bool => $reason !== '',
                ))
                : [];

            throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                'Claim cannot be submitted yet. '.implode(' ', $blockingReasons),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function invoiceClaimReadiness(array $invoice): array
    {
        $pricingContext = $this->pricingContext($invoice);
        $claimReadiness = is_array($pricingContext['claimReadiness'] ?? null)
            ? $pricingContext['claimReadiness']
            : [];

        if ($claimReadiness !== []) {
            return $claimReadiness;
        }

        $payerSummary = $this->payerSummary($invoice);
        $payerType = $this->normalizeNullableCode($payerSummary['payerType'] ?? null) ?? 'self_pay';
        $expectedPayerAmount = round(max((float) ($payerSummary['expectedPayerAmount'] ?? 0), 0), 2);

        return [
            'claimEligible' => $payerType !== 'self_pay' && $expectedPayerAmount > 0,
            'ready' => $payerType !== 'self_pay' && $expectedPayerAmount > 0 && ! (bool) ($payerSummary['requiresPreAuthorization'] ?? false),
            'blockingReasons' => (bool) ($payerSummary['requiresPreAuthorization'] ?? false)
                ? ['Selected payer contract requires pre-authorization review before claim submission.']
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function resolvePayerType(array $invoice, mixed $requestedPayerType): string
    {
        $payerSummary = $this->payerSummary($invoice);
        $payerType = $this->normalizeNullableCode($payerSummary['payerType'] ?? null)
            ?? $this->normalizeNullableCode($requestedPayerType)
            ?? 'other';

        return in_array($payerType, ['insurance', 'employer', 'government', 'donor', 'other'], true)
            ? $payerType
            : 'other';
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function resolvePayerName(array $invoice): ?string
    {
        $payerSummary = $this->payerSummary($invoice);
        $payerName = trim((string) ($payerSummary['payerName'] ?? ''));

        return $payerName !== '' ? $payerName : null;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function resolvePayerReference(array $invoice): ?string
    {
        $pricingContext = $this->pricingContext($invoice);
        $visitCoverage = is_array($pricingContext['visitCoverage'] ?? null)
            ? $pricingContext['visitCoverage']
            : [];

        return $this->normalizeNullableText($visitCoverage['coverageReference'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function pricingContext(array $invoice): array
    {
        return is_array($invoice['pricing_context'] ?? null)
            ? $invoice['pricing_context']
            : [];
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function payerSummary(array $invoice): array
    {
        $pricingContext = $this->pricingContext($invoice);

        return is_array($pricingContext['payerSummary'] ?? null)
            ? $pricingContext['payerSummary']
            : [];
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeNullableCode(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'invoice_id',
            'patient_id',
            'patient_insurance_record_id',
            'admission_id',
            'appointment_id',
            'payer_type',
            'payer_name',
            'payer_plan_name',
            'payer_reference',
            'member_id',
            'policy_number',
            'card_number',
            'verification_reference',
            'claim_readiness',
            'claim_amount',
            'currency_code',
            'submitted_at',
            'adjudicated_at',
            'approved_amount',
            'rejected_amount',
            'settled_amount',
            'reconciliation_shortfall_amount',
            'settled_at',
            'settlement_reference',
            'decision_reason',
            'notes',
            'status',
            'reconciliation_status',
            'reconciliation_exception_status',
            'reconciliation_follow_up_status',
            'reconciliation_follow_up_due_at',
            'reconciliation_follow_up_note',
            'reconciliation_follow_up_updated_at',
            'reconciliation_follow_up_updated_by_user_id',
            'reconciliation_notes',
            'status_reason',
        ];

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;

            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }
}
