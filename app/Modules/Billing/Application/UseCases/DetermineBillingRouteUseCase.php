<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\PatientInsuranceRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;

class DetermineBillingRouteUseCase
{
    public function __construct(
        private readonly PatientInsuranceRepositoryInterface $patientInsuranceRepository,
        private readonly BillingPayerContractRepositoryInterface $payerContractRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
    ) {}

    /**
     * Determine the billing route for a patient
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function execute(array $payload): array
    {
        $patientId = (string) $payload['patient_id'];
        $serviceId = $payload['service_id'] ?? null;

        $route = $this->determineRoute($patientId, $serviceId);

        return $route;
    }

    /**
     * @return array<string, mixed>
     */
    private function determineRoute(string $patientId, ?string $serviceId): array
    {
        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();

        // Check for active insurance
        $activeInsurance = $this->patientInsuranceRepository->findActiveInsurance(
            $patientId,
            $tenantId
        );

        if ($activeInsurance === null || $activeInsurance['status'] !== 'active') {
            // No active insurance = cash billing
            return [
                'routing_decision' => 'cash',
                'payer_type' => 'self_pay',
                'payer_id' => null,
                'payer_name' => 'Patient (Cash)',
                'use_insurance_pricing' => false,
                'reason' => 'No active insurance found',
            ];
        }

        $insuranceProvider = trim((string) ($activeInsurance['insurance_provider'] ?? ''));
        $policyNumber = $activeInsurance['policy_number'];
        $verificationStatus = strtolower((string) ($activeInsurance['verification_status'] ?? 'unverified'));

        if (! in_array($verificationStatus, ['verified', 'unverified'], true)) {
            return [
                'routing_decision' => 'cash',
                'payer_type' => 'self_pay_unverified_insurance',
                'payer_id' => null,
                'payer_name' => 'Patient (Insurance not verified)',
                'use_insurance_pricing' => false,
                'reason' => 'Patient insurance verification is not usable for billing',
                'patient_insurance_record_id' => $activeInsurance['id'] ?? null,
                'verification_status' => $verificationStatus,
            ];
        }

        $contract = null;
        if (! empty($activeInsurance['billing_payer_contract_id'])) {
            $contract = $this->payerContractRepository->findById((string) $activeInsurance['billing_payer_contract_id']);
        }

        if ($contract === null && $insuranceProvider !== '') {
            $contract = $this->payerContractRepository->findActiveContractByProvider(
                $insuranceProvider,
                $tenantId,
                $facilityId
            );
        }

        if ($contract === null) {
            // Insurance exists but no contract = route to cash (can't bill insurance)
            return [
                'routing_decision' => 'cash',
                'payer_type' => 'self_pay_unverified_insurance',
                'payer_id' => null,
                'payer_name' => 'Patient (Insurance unverified)',
                'use_insurance_pricing' => false,
                'reason' => 'No active contract for insurance provider',
                'patient_insurance_record_id' => $activeInsurance['id'] ?? null,
                'verification_status' => $verificationStatus,
            ];
        }

        // Has active contract
        return [
            'routing_decision' => 'insurance',
            'payer_type' => 'insurance',
            'payer_id' => $contract['id'] ?? $contract['billing_payer_contract_id'],
            'payer_name' => $contract['payer_name'] ?? $insuranceProvider,
            'payer_plan_name' => $contract['payer_plan_name'] ?? $activeInsurance['plan_name'] ?? null,
            'use_insurance_pricing' => true,
            'patient_insurance_record_id' => $activeInsurance['id'] ?? null,
            'insurance_type' => $activeInsurance['insurance_type'],
            'policy_number' => $policyNumber,
            'member_id' => $activeInsurance['member_id'] ?? null,
            'card_number' => $activeInsurance['card_number'] ?? null,
            'verification_status' => $verificationStatus,
            'verification_reference' => $activeInsurance['verification_reference'] ?? null,
            'coverage_level' => $activeInsurance['coverage_level'] ?? null,
            'coverage_percent' => $contract['default_coverage_percent'] ?? null,
            'copay_percent' => $activeInsurance['copay_percent'] ?? null,
            'requires_pre_authorization' => (bool) ($contract['requires_pre_authorization'] ?? false),
            'reason' => 'Active insurance with valid contract',
        ];
    }
}
