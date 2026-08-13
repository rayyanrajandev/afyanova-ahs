<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Encounter\Domain\Repositories\EncounterAuditLogRepositoryInterface;
use App\Modules\Encounter\Infrastructure\Models\EncounterDiagnosisModel;

class RemoveEncounterDiagnosisUseCase
{
    public function __construct(
        private readonly EncounterAuditLogRepositoryInterface $encounterAuditLogRepository,
    ) {}

    public function execute(string $encounterId, string $diagnosisId, ?int $actorId): bool
    {
        $diagnosis = EncounterDiagnosisModel::query()
            ->where('encounter_id', $encounterId)
            ->where('id', $diagnosisId)
            ->first();

        if ($diagnosis === null) {
            return false;
        }

        $before = $diagnosis->toArray();
        $diagnosis->delete();

        $this->encounterAuditLogRepository->write(
            encounterId: $encounterId,
            action: 'encounter.diagnosis.removed',
            actorId: $actorId,
            changes: ['before' => $before],
        );

        return true;
    }
}
