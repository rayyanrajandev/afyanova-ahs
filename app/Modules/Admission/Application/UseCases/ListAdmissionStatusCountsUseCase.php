<?php

namespace App\Modules\Admission\Application\UseCases;

use App\Modules\Admission\Domain\Repositories\AdmissionRepositoryInterface;

class ListAdmissionStatusCountsUseCase
{
    public function __construct(private readonly AdmissionRepositoryInterface $admissionRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' ? null : $patientId;

        $ward = isset($filters['ward']) ? trim((string) $filters['ward']) : null;
        $ward = $ward === '' ? null : $ward;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        $dischargedFrom = isset($filters['dischargedFrom']) ? trim((string) $filters['dischargedFrom']) : null;
        $dischargedFrom = $dischargedFrom === '' ? null : $dischargedFrom;

        $dischargedTo = isset($filters['dischargedTo']) ? trim((string) $filters['dischargedTo']) : null;
        $dischargedTo = $dischargedTo === '' ? null : $dischargedTo;

        return $this->admissionRepository->statusCounts(
            query: $query,
            patientId: $patientId,
            ward: $ward,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            dischargedFrom: $dischargedFrom,
            dischargedTo: $dischargedTo,
        );
    }
}
