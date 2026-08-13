<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Domain\Repositories\PatientAllergyRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientMedicationProfileRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;

class GetPatientMedicationReconciliationUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientAllergyRepositoryInterface $patientAllergyRepository,
        private readonly PatientMedicationProfileRepositoryInterface $patientMedicationProfileRepository,
        private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository,
    ) {}

    public function execute(string $patientId): ?array
    {
        if ($this->patientRepository->findById($patientId) === null) {
            return null;
        }

        $activeAllergies = $this->patientAllergyRepository->listActiveByPatientId($patientId);
        $activeMedicationProfile = $this->patientMedicationProfileRepository->listActiveByPatientId($patientId);
        $activeDispensedOrders = $this->pharmacyOrderRepository->activeDispensedOrders($patientId, 25);
        $unreconciledDispensedOrders = $this->pharmacyOrderRepository->unreconciledReleasedOrdersForPatient(
            patientId: $patientId,
            limit: 25,
        );

        $continueCandidates = array_values(array_filter(
            $activeDispensedOrders,
            fn (array $order): bool => $this->hasMedicationProfileMatch(
                $order,
                $activeMedicationProfile,
            ),
        ));

        $profileWithoutDispensedOrders = array_values(array_filter(
            $activeMedicationProfile,
            fn (array $profile): bool => ! $this->hasDispensedOrderMatch(
                $profile,
                $activeDispensedOrders,
            ),
        ));

        $newOrdersToProfile = array_values(array_filter(
            $activeDispensedOrders,
            fn (array $order): bool => ! $this->hasMedicationProfileMatch(
                $order,
                $activeMedicationProfile,
            ),
        ));

        $reviewRequired = $unreconciledDispensedOrders !== []
            || $profileWithoutDispensedOrders !== []
            || $newOrdersToProfile !== [];

        return [
            'counts' => [
                'activeAllergies' => count($activeAllergies),
                'activeMedicationProfile' => count($activeMedicationProfile),
                'activeDispensedOrders' => count($activeDispensedOrders),
                'unreconciledDispensedOrders' => count($unreconciledDispensedOrders),
                'continueCandidates' => count($continueCandidates),
                'reviewRequired' => $reviewRequired ? 1 : 0,
            ],
            'activeAllergies' => $activeAllergies,
            'activeMedicationProfile' => $activeMedicationProfile,
            'activeDispensedOrders' => $activeDispensedOrders,
            'unreconciledDispensedOrders' => $unreconciledDispensedOrders,
            'continueCandidates' => $continueCandidates,
            'profileWithoutDispensedOrders' => $profileWithoutDispensedOrders,
            'newOrdersToProfile' => $newOrdersToProfile,
            'suggestedActions' => array_values(array_filter([
                $unreconciledDispensedOrders !== []
                    ? 'Reconcile released medication orders that are still pending pharmacist or clinician follow-up.'
                    : null,
                $profileWithoutDispensedOrders !== []
                    ? 'Review current medication list entries that do not map to active dispensed therapy and confirm whether they should stay active.'
                    : null,
                $newOrdersToProfile !== []
                    ? 'Add or update newly dispensed therapy in the current medication list.'
                    : null,
            ])),
        ];
    }

    private function hasMedicationProfileMatch(array $order, array $profiles): bool
    {
        $orderCode = $this->normalizeText($order['medication_code'] ?? null);
        $orderName = $this->normalizeText($order['medication_name'] ?? null);

        foreach ($profiles as $profile) {
            $profileCode = $this->normalizeText($profile['medication_code'] ?? null);
            $profileName = $this->normalizeText($profile['medication_name'] ?? null);

            if ($orderCode !== '' && $profileCode !== '' && $orderCode === $profileCode) {
                return true;
            }

            if ($orderName !== '' && $profileName !== '' && $orderName === $profileName) {
                return true;
            }
        }

        return false;
    }

    private function hasDispensedOrderMatch(array $profile, array $orders): bool
    {
        $profileCode = $this->normalizeText($profile['medication_code'] ?? null);
        $profileName = $this->normalizeText($profile['medication_name'] ?? null);

        foreach ($orders as $order) {
            $orderCode = $this->normalizeText($order['medication_code'] ?? null);
            $orderName = $this->normalizeText($order['medication_name'] ?? null);

            if ($profileCode !== '' && $orderCode !== '' && $profileCode === $orderCode) {
                return true;
            }

            if ($profileName !== '' && $orderName !== '' && $profileName === $orderName) {
                return true;
            }
        }

        return false;
    }

    private function normalizeText(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
