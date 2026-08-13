<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Encounter\Application\Exceptions\ClinicalDocumentationNotAllowedOnServiceEncounterException;
use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Encounter\Domain\Repositories\EncounterAuditLogRepositoryInterface;
use App\Modules\Encounter\Domain\ValueObjects\EncounterDiagnosisType;
use App\Modules\Encounter\Infrastructure\Models\EncounterDiagnosisModel;
use App\Modules\Platform\Domain\ValueObjects\VisitCategory;

class AddEncounterDiagnosisUseCase
{
    public function __construct(
        private readonly EncounterResolverService $encounterResolverService,
        private readonly EncounterAuditLogRepositoryInterface $encounterAuditLogRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function execute(string $encounterId, array $data, ?int $actorId): ?array
    {
        $encounter = $this->encounterResolverService->findById($encounterId);
        if ($encounter === null) {
            return null;
        }

        if ($encounter->visit_category === VisitCategory::DIRECT_SERVICE->value) {
            throw new ClinicalDocumentationNotAllowedOnServiceEncounterException();
        }

        $diagnosisType = strtolower(trim((string) ($data['diagnosisType'] ?? EncounterDiagnosisType::SECONDARY->value)));
        if (! in_array($diagnosisType, EncounterDiagnosisType::values(), true)) {
            $diagnosisType = EncounterDiagnosisType::SECONDARY->value;
        }

        // An encounter has at most one primary diagnosis — recording a new one
        // demotes the previous primary rather than rejecting the request, so
        // clinicians can correct the primary diagnosis without an extra step.
        if ($diagnosisType === EncounterDiagnosisType::PRIMARY->value) {
            EncounterDiagnosisModel::query()
                ->where('encounter_id', $encounterId)
                ->where('diagnosis_type', EncounterDiagnosisType::PRIMARY->value)
                ->update(['diagnosis_type' => EncounterDiagnosisType::SECONDARY->value]);
        }

        $diagnosis = EncounterDiagnosisModel::query()->create([
            'encounter_id' => $encounterId,
            'diagnosis_code' => trim((string) ($data['diagnosisCode'] ?? '')),
            'diagnosis_description' => trim((string) ($data['diagnosisDescription'] ?? '')) !== ''
                ? trim((string) $data['diagnosisDescription'])
                : null,
            'diagnosis_type' => $diagnosisType,
            'recorded_by_user_id' => $actorId,
            'recorded_at' => now(),
        ]);

        $this->encounterAuditLogRepository->write(
            encounterId: $encounterId,
            action: 'encounter.diagnosis.added',
            actorId: $actorId,
            changes: ['after' => $diagnosis->toArray()],
        );

        return $diagnosis->toArray();
    }
}
