<?php

namespace App\Modules\MedicalRecord\Infrastructure\Repositories;

use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordVersionRepositoryInterface;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordVersionModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentMedicalRecordVersionRepository implements MedicalRecordVersionRepositoryInterface
{
    public function create(
        string $medicalRecordId,
        array $snapshot,
        array $changedFields,
        ?int $createdByUserId,
    ): array {
        /** @var array<string, mixed> $created */
        $created = DB::transaction(function () use ($medicalRecordId, $snapshot, $changedFields, $createdByUserId): array {
            $latestVersion = MedicalRecordVersionModel::query()
                ->where('medical_record_id', $medicalRecordId)
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first(['version_number']);

            $latestVersionNumber = (int) ($latestVersion?->version_number ?? 0);

            $model = MedicalRecordVersionModel::query()->create([
                'medical_record_id' => $medicalRecordId,
                'version_number' => $latestVersionNumber + 1,
                'snapshot' => $snapshot,
                'changed_fields' => array_values(array_unique(array_map(
                    static fn (mixed $value): string => (string) $value,
                    $changedFields,
                ))),
                'created_by_user_id' => $createdByUserId,
                'created_at' => now(),
            ]);

            return $model->toArray();
        });

        return $created;
    }

    public function listByMedicalRecordId(
        string $medicalRecordId,
        int $page,
        int $perPage,
    ): array {
        $paginator = MedicalRecordVersionModel::query()
            ->where('medical_record_id', $medicalRecordId)
            ->orderByDesc('version_number')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult($paginator);
    }

    public function findById(string $id): ?array
    {
        return MedicalRecordVersionModel::query()->find($id)?->toArray();
    }

    public function findLatestByMedicalRecordId(string $medicalRecordId): ?array
    {
        return MedicalRecordVersionModel::query()
            ->where('medical_record_id', $medicalRecordId)
            ->orderByDesc('version_number')
            ->first()
            ?->toArray();
    }

    public function findByMedicalRecordAndVersionNumber(string $medicalRecordId, int $versionNumber): ?array
    {
        return MedicalRecordVersionModel::query()
            ->where('medical_record_id', $medicalRecordId)
            ->where('version_number', $versionNumber)
            ->first()
            ?->toArray();
    }

    private function toPagedResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (MedicalRecordVersionModel $version): array => $version->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }
}
