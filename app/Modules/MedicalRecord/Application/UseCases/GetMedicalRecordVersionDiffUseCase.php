<?php

namespace App\Modules\MedicalRecord\Application\UseCases;

use App\Modules\MedicalRecord\Application\Exceptions\InvalidMedicalRecordVersionComparisonException;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordVersionRepositoryInterface;

class GetMedicalRecordVersionDiffUseCase
{
    public function __construct(
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
        private readonly MedicalRecordVersionRepositoryInterface $medicalRecordVersionRepository,
    ) {}

    public function execute(string $medicalRecordId, string $versionId, ?string $againstVersionId = null): ?array
    {
        $record = $this->medicalRecordRepository->findById($medicalRecordId);
        if (! $record) {
            return null;
        }

        $targetVersion = $this->medicalRecordVersionRepository->findById($versionId);
        if (! $targetVersion || ($targetVersion['medical_record_id'] ?? null) !== $medicalRecordId) {
            return null;
        }

        $baseVersion = null;
        if ($againstVersionId !== null && trim($againstVersionId) !== '') {
            $baseVersion = $this->medicalRecordVersionRepository->findById($againstVersionId);
            if (! $baseVersion || ($baseVersion['medical_record_id'] ?? null) !== $medicalRecordId) {
                throw new InvalidMedicalRecordVersionComparisonException(
                    'Comparison version must belong to the same medical record.',
                );
            }
        } else {
            $targetVersionNumber = (int) ($targetVersion['version_number'] ?? 0);
            if ($targetVersionNumber > 1) {
                $baseVersion = $this->medicalRecordVersionRepository->findByMedicalRecordAndVersionNumber(
                    medicalRecordId: $medicalRecordId,
                    versionNumber: $targetVersionNumber - 1,
                );
            }
        }

        $targetSnapshot = is_array($targetVersion['snapshot'] ?? null) ? $targetVersion['snapshot'] : [];
        $baseSnapshot = is_array($baseVersion['snapshot'] ?? null) ? $baseVersion['snapshot'] : [];

        $diff = $this->buildSnapshotDiff($baseSnapshot, $targetSnapshot);

        return [
            'targetVersion' => $this->versionMeta($targetVersion),
            'baseVersion' => $baseVersion ? $this->versionMeta($baseVersion) : null,
            'diff' => $diff,
            'summary' => [
                'changedFieldCount' => count($diff),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $target
     * @return array<int, array<string, mixed>>
     */
    private function buildSnapshotDiff(array $base, array $target): array
    {
        $fields = array_values(array_unique(array_merge(array_keys($base), array_keys($target))));
        sort($fields);

        $diff = [];
        foreach ($fields as $field) {
            $before = $base[$field] ?? null;
            $after = $target[$field] ?? null;

            if ($before === $after) {
                continue;
            }

            $diff[] = [
                'field' => $field,
                'before' => $before,
                'after' => $after,
            ];
        }

        return $diff;
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>
     */
    private function versionMeta(array $version): array
    {
        return [
            'id' => $version['id'] ?? null,
            'medical_record_id' => $version['medical_record_id'] ?? null,
            'version_number' => $version['version_number'] ?? null,
            'changed_fields' => $version['changed_fields'] ?? [],
            'created_by_user_id' => $version['created_by_user_id'] ?? null,
            'created_at' => $version['created_at'] ?? null,
        ];
    }
}
