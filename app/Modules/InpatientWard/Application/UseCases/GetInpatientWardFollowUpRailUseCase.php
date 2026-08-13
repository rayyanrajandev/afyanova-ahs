<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Application\Exceptions\InpatientWardAdmissionNotFoundException;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCensusRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardFollowUpRailRepositoryInterface;

class GetInpatientWardFollowUpRailUseCase
{
    public function __construct(
        private readonly InpatientWardCensusRepositoryInterface $censusRepository,
        private readonly InpatientWardFollowUpRailRepositoryInterface $followUpRailRepository,
    ) {}

    public function execute(string $admissionId, int $itemLimit = 3): array
    {
        $normalizedAdmissionId = trim($admissionId);
        $admission = $this->censusRepository->findCurrentAdmissionById($normalizedAdmissionId);

        if (! $admission) {
            throw new InpatientWardAdmissionNotFoundException(
                'Inpatient admission not found in current ward census.',
            );
        }

        return [
            'admissionId' => $admission['id'],
            'patientId' => $admission['patient_id'] ?? null,
            'generatedAt' => now()->toIso8601String(),
            'modules' => $this->followUpRailRepository->summarizeForAdmission($admission['id'], $itemLimit),
        ];
    }
}
