<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use Illuminate\Support\Str;

class ListPharmacyOrderStatusCountsUseCase
{
    public function __construct(private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' ? null : $patientId;
        if ($patientId !== null && ! Str::isUuid($patientId)) {
            $patientId = null;
        }

        $appointmentId = isset($filters['appointmentId']) ? trim((string) $filters['appointmentId']) : null;
        $appointmentId = $appointmentId === '' ? null : $appointmentId;
        if ($appointmentId !== null && ! Str::isUuid($appointmentId)) {
            $appointmentId = null;
        }

        $admissionId = isset($filters['admissionId']) ? trim((string) $filters['admissionId']) : null;
        $admissionId = $admissionId === '' ? null : $admissionId;
        if ($admissionId !== null && ! Str::isUuid($admissionId)) {
            $admissionId = null;
        }

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->pharmacyOrderRepository->statusCounts(
            query: $query,
            patientId: $patientId,
            appointmentId: $appointmentId,
            admissionId: $admissionId,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}
