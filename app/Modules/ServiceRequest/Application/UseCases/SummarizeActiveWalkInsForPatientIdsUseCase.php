<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;

class SummarizeActiveWalkInsForPatientIdsUseCase
{
    public function __construct(
        private readonly ServiceRequestRepositoryInterface $serviceRequestRepository,
    ) {}

    /**
     * @param  array<int, string>  $patientIds
     * @return array<string, string> Patient id -> one-line summary for clerks / list UI
     */
    public function execute(array $patientIds): array
    {
        if ($patientIds === []) {
            return [];
        }

        $grouped = $this->serviceRequestRepository->findActiveByPatientIds($patientIds);
        $out = [];

        foreach ($grouped as $patientId => $rows) {
            $parts = [];

            foreach ($rows as $row) {
                $serviceType = is_string($row['service_type'] ?? null)
                    ? (string) $row['service_type']
                    : '';
                $status = is_string($row['status'] ?? null)
                    ? (string) $row['status']
                    : '';

                $parts[] = sprintf(
                    '%s walk-in — %s',
                    $this->laneLabel($serviceType),
                    $this->statusPhrase($status),
                );
            }

            if ($parts !== []) {
                $out[$patientId] = implode(' · ', $parts);
            }
        }

        return $out;
    }

    private function laneLabel(string $serviceType): string
    {
        return match ($serviceType) {
            'laboratory' => 'Lab',
            'pharmacy' => 'Pharmacy',
            'radiology' => 'Imaging',
            'theatre_procedure' => 'Procedure',
            default => $serviceType !== '' ? $serviceType : 'Service',
        };
    }

    private function statusPhrase(string $status): string
    {
        return match ($status) {
            'pending' => 'waiting',
            'in_progress' => 'in progress',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => $status !== '' ? str_replace('_', ' ', $status) : 'active',
        };
    }
}
