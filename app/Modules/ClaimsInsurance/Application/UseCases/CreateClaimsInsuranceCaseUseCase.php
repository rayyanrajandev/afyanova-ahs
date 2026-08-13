<?php

namespace App\Modules\ClaimsInsurance\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\PatientInsuranceRepositoryInterface;
use App\Modules\ClaimsInsurance\Application\Exceptions\InvoiceNotEligibleForClaimsInsuranceCaseException;
use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseAuditLogRepositoryInterface;
use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseRepositoryInterface;
use App\Modules\ClaimsInsurance\Domain\Services\BillingInvoiceLookupServiceInterface;
use App\Modules\ClaimsInsurance\Domain\ValueObjects\ClaimsInsuranceCaseStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Str;
use RuntimeException;

class CreateClaimsInsuranceCaseUseCase
{
    public function __construct(
        private readonly ClaimsInsuranceCaseAuditLogRepositoryInterface $auditLogRepository,
        private readonly ClaimsInsuranceCaseRepositoryInterface $claimsInsuranceCaseRepository,
        private readonly PatientInsuranceRepositoryInterface $patientInsuranceRepository,
        private readonly BillingInvoiceLookupServiceInterface $billingInvoiceLookupService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $invoiceId = (string) $payload['invoice_id'];
        $invoice = $this->billingInvoiceLookupService->findInvoiceById($invoiceId);
        if (! $invoice) {
            throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                'Claims insurance case requires an existing billing invoice.',
            );
        }

        if ($this->claimsInsuranceCaseRepository->existsActiveForInvoice($invoiceId)) {
            throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                'This invoice already has an active claims insurance case.',
            );
        }

        $this->assertInvoiceEligibleForClaim($invoice, $payload);

        $patientId = $invoice['patient_id'] ?? null;
        if (! is_string($patientId) || trim($patientId) === '') {
            throw new InvoiceNotEligibleForClaimsInsuranceCaseException(
                'Billing invoice is missing patient context for claim creation.',
            );
        }

        $payload['claim_number'] = $this->generateClaimNumber();
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
        $patientInsurance = $this->resolvePatientInsurance($patientId, $payload);
        $claimReadiness = $this->invoiceClaimReadiness($invoice);
        $payload = $this->enrichInsuranceClaimPayload(
            payload: $payload,
            invoice: $invoice,
            patientInsurance: $patientInsurance,
            claimReadiness: $claimReadiness,
        );
        $payload['status'] = array_key_exists('submitted_at', $payload) && $payload['submitted_at'] !== null
            ? ClaimsInsuranceCaseStatus::SUBMITTED->value
            : ClaimsInsuranceCaseStatus::DRAFT->value;
        $payload['reconciliation_status'] = 'pending';
        $payload['tenant_id'] = $this->platformScopeContext->tenantId();
        $payload['facility_id'] = $this->platformScopeContext->facilityId();

        $createdCase = $this->claimsInsuranceCaseRepository->create($payload);

        $this->auditLogRepository->write(
            claimsInsuranceCaseId: $createdCase['id'],
            action: 'claims-insurance-case.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($createdCase),
            ],
        );

        return $createdCase;
    }

    private function generateClaimNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'CLM'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->claimsInsuranceCaseRepository->existsByClaimNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique claims insurance case number.');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $case): array
    {
        $tracked = [
            'claim_number',
            'tenant_id',
            'facility_id',
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

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $case[$field] ?? null;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function resolveClaimAmount(array $invoice): float
    {
        $pricingContext = is_array($invoice['pricing_context'] ?? null)
            ? $invoice['pricing_context']
            : [];
        $payerSummary = is_array($pricingContext['payerSummary'] ?? null)
            ? $pricingContext['payerSummary']
            : [];
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
                'Issue the billing invoice before creating an insurance claim.',
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
        $coverageReference = $this->normalizeNullableText($visitCoverage['coverageReference'] ?? null);

        return $coverageReference;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolvePatientInsurance(string $patientId, array $payload): ?array
    {
        $requestedInsuranceId = $this->normalizeNullableText($payload['patient_insurance_record_id'] ?? null);
        if ($requestedInsuranceId !== null) {
            $record = $this->patientInsuranceRepository->findById($requestedInsuranceId);

            return ($record['patient_id'] ?? null) === $patientId ? $record : null;
        }

        $tenantId = (string) ($this->platformScopeContext->tenantId() ?? '');

        return $this->patientInsuranceRepository->findActiveInsurance($patientId, $tenantId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $invoice
     * @param  array<string, mixed>|null  $patientInsurance
     * @param  array<string, mixed>  $claimReadiness
     * @return array<string, mixed>
     */
    private function enrichInsuranceClaimPayload(
        array $payload,
        array $invoice,
        ?array $patientInsurance,
        array $claimReadiness,
    ): array {
        $pricingContext = $this->pricingContext($invoice);
        $payerSummary = $this->payerSummary($invoice);

        $payload['patient_insurance_record_id'] ??= $patientInsurance['id'] ?? null;
        $payload['payer_plan_name'] ??= $payerSummary['payerPlanName'] ?? $patientInsurance['plan_name'] ?? null;
        $payload['member_id'] ??= $patientInsurance['member_id'] ?? null;
        $payload['policy_number'] ??= $patientInsurance['policy_number'] ?? null;
        $payload['card_number'] ??= $patientInsurance['card_number'] ?? null;
        $payload['verification_reference'] ??= $patientInsurance['verification_reference'] ?? null;
        $payload['claim_readiness'] = [
            ...$claimReadiness,
            'patientInsuranceRecordId' => $payload['patient_insurance_record_id'] ?? null,
            'verificationStatus' => $patientInsurance['verification_status'] ?? null,
            'payerSummary' => $payerSummary,
            'visitCoverage' => is_array($pricingContext['visitCoverage'] ?? null)
                ? $pricingContext['visitCoverage']
                : null,
        ];

        return $payload;
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
}
