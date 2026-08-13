<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Application\Services\StaffCredentialingSummaryResolver;
use App\Modules\Staff\Domain\Repositories\StaffProfessionalRegistrationRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffRegulatoryProfileRepositoryInterface;

class BatchGetStaffCredentialingSummariesUseCase
{
    private const MAX_IDS = 100;

    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffRegulatoryProfileRepositoryInterface $staffRegulatoryProfileRepository,
        private readonly StaffProfessionalRegistrationRepositoryInterface $staffProfessionalRegistrationRepository,
        private readonly StaffCredentialingSummaryResolver $summaryResolver,
    ) {}

    /**
     * @param  array<int, string>  $staffProfileIds
     * @return array<int, array<string, mixed>>
     */
    public function execute(array $staffProfileIds): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $staffProfileIds),
            static fn (string $value): bool => $value !== '',
        ))), 0, self::MAX_IDS);

        if ($ids === []) {
            return [];
        }

        $profiles = $this->staffProfileRepository->findByIds($ids);
        if ($profiles === []) {
            return [];
        }

        $profileIds = array_values(array_filter(array_map(
            static fn (array $profile): string => trim((string) ($profile['id'] ?? '')),
            $profiles,
        )));
        $regulatoryProfiles = $this->staffRegulatoryProfileRepository->findByStaffProfileIds($profileIds);
        $registrations = $this->staffProfessionalRegistrationRepository->listAllByStaffProfileIds($profileIds);

        $profilesById = [];
        foreach ($profiles as $profile) {
            $id = trim((string) ($profile['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $profilesById[$id] = $profile;
        }

        $summaries = [];
        foreach ($ids as $id) {
            if (! isset($profilesById[$id])) {
                continue;
            }

            $summaries[] = $this->summaryResolver->resolve(
                staffProfile: $profilesById[$id],
                regulatoryProfile: $regulatoryProfiles[$id] ?? null,
                registrations: $registrations[$id] ?? [],
            );
        }

        return $summaries;
    }
}
