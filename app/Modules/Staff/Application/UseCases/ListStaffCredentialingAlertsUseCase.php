<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\StaffProfessionalRegistrationRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffRegulatorCode;

class ListStaffCredentialingAlertsUseCase
{
    /**
     * @var array<int, string>
     */
    private const ALERT_TYPES = [
        'missing_regulatory_profile',
        'good_standing_risk',
        'expired_license',
        'expired_registration',
        'verification_pending',
        'due_soon',
    ];

    /**
     * @var array<int, string>
     */
    private const ALERT_STATES = [
        'blocked',
        'pending_verification',
        'watch',
    ];

    public function __construct(
        private readonly StaffProfessionalRegistrationRepositoryInterface $staffProfessionalRegistrationRepository,
    ) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $facilityId = isset($filters['facilityId']) ? trim((string) $filters['facilityId']) : null;
        $facilityId = $facilityId === '' ? null : $facilityId;

        $regulatorCode = isset($filters['regulatorCode']) ? strtolower(trim((string) $filters['regulatorCode'])) : null;
        if (! in_array($regulatorCode, StaffRegulatorCode::values(), true)) {
            $regulatorCode = null;
        }

        $cadreCode = isset($filters['cadreCode']) ? trim((string) $filters['cadreCode']) : null;
        $cadreCode = $cadreCode === '' ? null : $cadreCode;

        $alertType = isset($filters['alertType']) ? trim((string) $filters['alertType']) : null;
        if (! in_array($alertType, self::ALERT_TYPES, true)) {
            $alertType = null;
        }

        $alertState = isset($filters['alertState']) ? trim((string) $filters['alertState']) : null;
        if (! in_array($alertState, self::ALERT_STATES, true)) {
            $alertState = null;
        }

        $sortBy = isset($filters['sortBy']) ? trim((string) $filters['sortBy']) : null;
        $sortBy = in_array($sortBy, ['employeeNumber', 'alertType', 'alertState', 'regulatorCode', 'expiresAt'], true)
            ? $sortBy
            : 'expiresAt';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        return $this->staffProfessionalRegistrationRepository->searchCredentialingAlerts(
            query: $query,
            facilityId: $facilityId,
            regulatorCode: $regulatorCode,
            cadreCode: $cadreCode,
            alertType: $alertType,
            alertState: $alertState,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
