<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Infrastructure\Repositories\PatientInsuranceAuditEventRepository;

class ListPatientInsuranceAuditEventsUseCase
{
    public function __construct(
        private readonly PatientInsuranceAuditEventRepository $repository,
    ) {}

    public function execute(string $patientId, array $filters = []): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? $filters['per_page'] ?? 15), 1), 100);

        return $this->repository->listForPatient($patientId, $page, $perPage);
    }
}
