<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;

class ListPatientInsuranceOptionsUseCase
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $payerContractRepository,
    ) {}

    public function execute(array $filters = []): array
    {
        $contracts = $this->payerContractRepository->search(
            query: $filters['query'] ?? null,
            payerType: null,
            status: 'active',
            currencyCode: null,
            requiresPreAuthorization: null,
            page: 1,
            perPage: 100,
            sortBy: 'payer_name',
            sortDirection: 'asc',
        );
        $payerTypes = array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) config('patient_insurance.contract_payer_types', ['insurance', 'government', 'employer', 'donor', 'other']),
        );

        return [
            'providerPresets' => $this->providerPresets(),
            'payerContracts' => array_map(
                static fn (array $contract): array => [
                    'id' => $contract['id'] ?? null,
                    'contractCode' => $contract['contract_code'] ?? null,
                    'contractName' => $contract['contract_name'] ?? null,
                    'payerType' => $contract['payer_type'] ?? null,
                    'payerName' => $contract['payer_name'] ?? null,
                    'payerPlanCode' => $contract['payer_plan_code'] ?? null,
                    'payerPlanName' => $contract['payer_plan_name'] ?? null,
                    'defaultCoveragePercent' => $contract['default_coverage_percent'] ?? null,
                    'requiresPreAuthorization' => $contract['requires_pre_authorization'] ?? false,
                ],
                array_values(array_filter(
                    $contracts['data'] ?? [],
                    static fn (array $contract): bool => in_array(
                        strtolower(trim((string) ($contract['payer_type'] ?? ''))),
                        $payerTypes,
                        true,
                    ),
                )),
            ),
        ];
    }

    private function providerPresets(): array
    {
        $presets = array_values(array_filter(
            (array) config('patient_insurance.provider_presets', []),
            static fn (mixed $preset): bool => is_array($preset)
                && trim((string) ($preset['code'] ?? '')) !== ''
                && trim((string) ($preset['name'] ?? '')) !== '',
        ));

        return array_map(
            static fn (array $preset): array => [
                'code' => $preset['code'] ?? null,
                'name' => $preset['name'] ?? null,
                'category' => $preset['category'] ?? null,
                'insuranceType' => $preset['insurance_type'] ?? $preset['insuranceType'] ?? null,
            ],
            $presets,
        );
    }
}
