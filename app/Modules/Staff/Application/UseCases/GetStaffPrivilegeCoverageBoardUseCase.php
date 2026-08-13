<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Application\Services\StaffCredentialingSummaryResolver;
use App\Modules\Staff\Domain\Repositories\StaffDocumentRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffPrivilegeGrantRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfessionalRegistrationRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffRegulatoryProfileRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffProfileStatus;

class GetStaffPrivilegeCoverageBoardUseCase
{
    private const MAX_STAFF = 1000;

    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffPrivilegeGrantRepositoryInterface $staffPrivilegeGrantRepository,
        private readonly StaffDocumentRepositoryInterface $staffDocumentRepository,
        private readonly StaffRegulatoryProfileRepositoryInterface $staffRegulatoryProfileRepository,
        private readonly StaffProfessionalRegistrationRepositoryInterface $staffProfessionalRegistrationRepository,
        private readonly StaffCredentialingSummaryResolver $summaryResolver,
    ) {}

    public function execute(array $filters, bool $includeDocuments, bool $includeCredentialing): array
    {
        $status = $filters['status'] ?? null;
        if (! in_array($status, StaffProfileStatus::values(), true)) {
            $status = null;
        }

        $department = isset($filters['department']) ? trim((string) $filters['department']) : null;
        $department = $department === '' ? null : $department;

        $employmentType = isset($filters['employmentType']) ? trim((string) $filters['employmentType']) : null;
        $employmentType = $employmentType === '' ? null : $employmentType;

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $clinicalOnly = filter_var($filters['clinicalOnly'] ?? false, FILTER_VALIDATE_BOOL);
        $maxStaff = min(max((int) ($filters['maxStaff'] ?? self::MAX_STAFF), 1), self::MAX_STAFF);

        $profiles = $this->staffProfileRepository->search(
            query: $query,
            status: $status,
            department: $department,
            employmentType: $employmentType,
            clinicalOnly: $clinicalOnly,
            page: 1,
            perPage: $maxStaff,
            sortBy: 'updated_at',
            sortDirection: 'desc',
        );

        $staffRows = $profiles['data'] ?? [];
        $staffIds = array_values(array_filter(array_map(
            static fn (array $profile): string => trim((string) ($profile['id'] ?? '')),
            $staffRows,
        )));

        if ($staffIds === []) {
            return [
                'data' => [],
                'meta' => [
                    'totalMatchingStaff' => 0,
                    'includedDocuments' => $includeDocuments,
                    'includedCredentialing' => $includeCredentialing,
                ],
            ];
        }

        $grants = $this->staffPrivilegeGrantRepository->listByStaffProfileIds($staffIds);
        $documents = $includeDocuments ? $this->staffDocumentRepository->listByStaffProfileIds($staffIds, 'active') : [];
        $regulatoryProfiles = $includeCredentialing ? $this->staffRegulatoryProfileRepository->findByStaffProfileIds($staffIds) : [];
        $registrations = $includeCredentialing ? $this->staffProfessionalRegistrationRepository->listAllByStaffProfileIds($staffIds) : [];

        $data = array_map(function (array $profile) use ($grants, $documents, $regulatoryProfiles, $registrations, $includeCredentialing): array {
            $id = trim((string) ($profile['id'] ?? ''));

            return [
                ...$profile,
                'privileges' => $grants[$id] ?? [],
                'documents' => $documents[$id] ?? [],
                'credentialing_summary' => $includeCredentialing
                    ? $this->summaryResolver->resolve(
                        staffProfile: $profile,
                        regulatoryProfile: $regulatoryProfiles[$id] ?? null,
                        registrations: $registrations[$id] ?? [],
                    )
                    : null,
            ];
        }, $staffRows);

        return [
            'data' => $data,
            'meta' => [
                'totalMatchingStaff' => (int) ($profiles['meta']['total'] ?? count($data)),
                'includedDocuments' => $includeDocuments,
                'includedCredentialing' => $includeCredentialing,
            ],
        ];
    }
}
