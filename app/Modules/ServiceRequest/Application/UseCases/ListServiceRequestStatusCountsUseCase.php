<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\ServiceRequest\Application\Services\ServiceRequestDepartmentScope;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestServiceType;
use Illuminate\Support\Str;

class ListServiceRequestStatusCountsUseCase
{
    public function __construct(private readonly ServiceRequestRepositoryInterface $serviceRequestRepository) {}

    /** @see ListServiceRequestsUseCase::execute() for $scope's enforcement rules. */
    public function execute(array $filters, ServiceRequestDepartmentScope $scope): array
    {
        if ($scope->hasNoAssignedDepartment()) {
            return ['pending' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0, 'total' => 0];
        }

        $serviceType = $filters['serviceType'] ?? null;
        if (! in_array($serviceType, ServiceRequestServiceType::values(), true)) {
            $serviceType = null;
        }

        $priority = $filters['priority'] ?? null;
        if (! in_array($priority, ['routine', 'urgent'], true)) {
            $priority = null;
        }

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime !== '' ? $fromDateTime : null;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime !== '' ? $toDateTime : null;

        $departmentId = isset($filters['departmentId']) && Str::isUuid((string) $filters['departmentId'])
            ? (string) $filters['departmentId']
            : null;

        return $this->serviceRequestRepository->statusCounts(
            serviceType: $serviceType,
            priority: $priority,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            departmentId: $departmentId,
        );
    }
}
