<?php

namespace App\Modules\MedicalRecord\Infrastructure\Repositories;

use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordSignerAttestationRepositoryInterface;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordSignerAttestationModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentMedicalRecordSignerAttestationRepository implements MedicalRecordSignerAttestationRepositoryInterface
{
    public function create(
        string $medicalRecordId,
        int $attestedByUserId,
        string $attestationNote,
    ): array {
        $model = MedicalRecordSignerAttestationModel::query()->create([
            'medical_record_id' => $medicalRecordId,
            'attested_by_user_id' => $attestedByUserId,
            'attestation_note' => $attestationNote,
            'attested_at' => now(),
        ]);

        $model->load('attestedByUser:id,name');

        return $model->toArray();
    }

    public function listByMedicalRecordId(
        string $medicalRecordId,
        int $page,
        int $perPage,
    ): array {
        $paginator = MedicalRecordSignerAttestationModel::query()
            ->with('attestedByUser:id,name')
            ->where('medical_record_id', $medicalRecordId)
            ->orderByDesc('attested_at')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult($paginator);
    }

    private function toPagedResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (MedicalRecordSignerAttestationModel $attestation): array => $attestation->toArray(),
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
