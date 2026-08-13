<?php

namespace App\Modules\MedicalRecord\Infrastructure\Repositories;

use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordNoteType;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EloquentMedicalRecordRepository implements MedicalRecordRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $record = new MedicalRecordModel();
        $record->fill($attributes);
        $record->save();

        return $record->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = MedicalRecordModel::query()->with(['signedByUser:id,name', 'authorUser:id,name']);
        $this->applyPlatformScopeIfEnabled($query);
        $record = $query->find($id);

        return $record?->toArray();
    }

    public function findLatestDraftForAppointment(
        string $patientId,
        string $appointmentId,
        string $recordType,
    ): ?array {
        $query = MedicalRecordModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->where('patient_id', $patientId)
            ->where('appointment_id', $appointmentId)
            ->where('record_type', $recordType)
            ->where('status', MedicalRecordStatus::DRAFT->value)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first()
            ?->toArray();
    }

    public function findLatestDraftForEncounter(
        string $patientId,
        string $encounterId,
        string $recordType,
    ): ?array {
        $query = MedicalRecordModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->where('patient_id', $patientId)
            ->where('encounter_id', $encounterId)
            ->where('record_type', $recordType)
            ->where('status', MedicalRecordStatus::DRAFT->value)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first()
            ?->toArray();
    }

    public function hasDraftConsultationNoteForAppointment(string $appointmentId): bool
    {
        $query = MedicalRecordModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->where('appointment_id', $appointmentId)
            ->where('record_type', MedicalRecordNoteType::CONSULTATION_NOTE->value)
            ->where('status', MedicalRecordStatus::DRAFT->value)
            ->exists();
    }

    public function hasSignedConsultationNoteForAppointment(string $appointmentId): bool
    {
        $query = MedicalRecordModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->where('appointment_id', $appointmentId)
            ->where('record_type', MedicalRecordNoteType::CONSULTATION_NOTE->value)
            ->whereIn('status', [
                MedicalRecordStatus::FINALIZED->value,
                MedicalRecordStatus::AMENDED->value,
            ])
            ->exists();
    }

    public function hasSignedConsultationNoteForAppointments(array $appointmentIds): array
    {
        $result = array_fill_keys($appointmentIds, false);

        if ($appointmentIds === []) {
            return $result;
        }

        $query = MedicalRecordModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $signedAppointmentIds = $query
            ->whereIn('appointment_id', $appointmentIds)
            ->where('record_type', MedicalRecordNoteType::CONSULTATION_NOTE->value)
            ->whereIn('status', [
                MedicalRecordStatus::FINALIZED->value,
                MedicalRecordStatus::AMENDED->value,
            ])
            ->pluck('appointment_id')
            ->unique();

        foreach ($signedAppointmentIds as $appointmentId) {
            $result[$appointmentId] = true;
        }

        return $result;
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = MedicalRecordModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $record = $query->find($id);
        if (! $record) {
            return null;
        }

        $record->fill($attributes);
        $record->save();

        return $record->toArray();
    }

    public function updateWithOptimisticLock(
        string $id,
        array $attributes,
        ?string $expectedUpdatedAt,
        bool $forceDraftSave,
    ): array {
        /** @var array{outcome: 'updated', record: array<string, mixed>}|array{outcome: 'conflict', record: array<string, mixed>}|array{outcome: 'missing'} $result */
        $result = DB::transaction(function () use ($id, $attributes, $expectedUpdatedAt, $forceDraftSave): array {
            $query = MedicalRecordModel::query();
            $this->applyPlatformScopeIfEnabled($query);

            /** @var MedicalRecordModel|null $record */
            $record = $query
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return ['outcome' => 'missing'];
            }

            if (
                ! $forceDraftSave
                && $expectedUpdatedAt !== null
                && ! $this->updatedAtMatches($record->updated_at, $expectedUpdatedAt)
            ) {
                return [
                    'outcome' => 'conflict',
                    'record' => $record->fresh()?->toArray() ?? $record->toArray(),
                ];
            }

            $record->fill($attributes);
            $record->save();

            return [
                'outcome' => 'updated',
                'record' => $record->fresh()?->toArray() ?? $record->toArray(),
            ];
        });

        return $result;
    }

    public function updateWithLock(string $id, callable $mutator): array
    {
        return DB::transaction(function () use ($id, $mutator): array {
            $query = MedicalRecordModel::query();
            $this->applyPlatformScopeIfEnabled($query);

            /** @var MedicalRecordModel|null $record */
            $record = $query
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return ['outcome' => 'missing'];
            }

            $before = $record->toArray();
            $attributes = $mutator($before);

            $record->fill($attributes);
            $record->save();

            return [
                'outcome' => 'updated',
                'before' => $before,
                'record' => $record->fresh()?->toArray() ?? $record->toArray(),
            ];
        });
    }

    private function updatedAtMatches(mixed $current, mixed $expected): bool
    {
        if ($current === null || $expected === null) {
            return false;
        }

        try {
            return Carbon::parse((string) $current)->equalTo(Carbon::parse((string) $expected));
        } catch (\Throwable) {
            return false;
        }
    }

    public function existsByRecordNumber(string $recordNumber): bool
    {
        return MedicalRecordModel::query()
            ->where('record_number', $recordNumber)
            ->exists();
    }

    public function search(
        ?string $query,
        ?string $patientId,
        ?string $encounterId,
        ?string $appointmentId,
        ?string $appointmentReferralId,
        ?string $admissionId,
        ?string $theatreProcedureId,
        ?int $authorUserId,
        ?string $status,
        ?string $recordType,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['record_number', 'encounter_at', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'encounter_at';

        $queryBuilder = MedicalRecordModel::query()->with(['signedByUser:id,name', 'authorUser:id,name']);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('record_number', 'like', $like)
                        ->orWhere('record_type', 'like', $like)
                        ->orWhere('assessment', 'like', $like)
                        ->orWhere('plan', 'like', $like)
                        ->orWhere('diagnosis_code', 'like', $like);
                });
            })
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($encounterId, fn (Builder $builder, string $requestedEncounterId) => $builder->where('encounter_id', $requestedEncounterId))
            ->when($appointmentId, fn (Builder $builder, string $requestedAppointmentId) => $builder->where('appointment_id', $requestedAppointmentId))
            ->when($appointmentReferralId, fn (Builder $builder, string $requestedAppointmentReferralId) => $builder->where('appointment_referral_id', $requestedAppointmentReferralId))
            ->when($admissionId, fn (Builder $builder, string $requestedAdmissionId) => $builder->where('admission_id', $requestedAdmissionId))
            ->when($theatreProcedureId, fn (Builder $builder, string $requestedTheatreProcedureId) => $builder->where('theatre_procedure_id', $requestedTheatreProcedureId))
            ->when($authorUserId, fn (Builder $builder, int $requestedAuthorUserId) => $builder->where('author_user_id', $requestedAuthorUserId))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($recordType, fn (Builder $builder, string $requestedRecordType) => $builder->where('record_type', $requestedRecordType))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('encounter_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('encounter_at', '<=', $endDateTime))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCounts(
        ?string $query,
        ?string $patientId,
        ?string $encounterId,
        ?string $appointmentReferralId,
        ?string $admissionId,
        ?string $theatreProcedureId,
        ?string $recordType,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = MedicalRecordModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('record_number', 'like', $like)
                        ->orWhere('record_type', 'like', $like)
                        ->orWhere('assessment', 'like', $like)
                        ->orWhere('plan', 'like', $like)
                        ->orWhere('diagnosis_code', 'like', $like);
                });
            })
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($encounterId, fn (Builder $builder, string $requestedEncounterId) => $builder->where('encounter_id', $requestedEncounterId))
            ->when($appointmentReferralId, fn (Builder $builder, string $requestedAppointmentReferralId) => $builder->where('appointment_referral_id', $requestedAppointmentReferralId))
            ->when($admissionId, fn (Builder $builder, string $requestedAdmissionId) => $builder->where('admission_id', $requestedAdmissionId))
            ->when($theatreProcedureId, fn (Builder $builder, string $requestedTheatreProcedureId) => $builder->where('theatre_procedure_id', $requestedTheatreProcedureId))
            ->when($recordType, fn (Builder $builder, string $requestedRecordType) => $builder->where('record_type', $requestedRecordType))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('encounter_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('encounter_at', '<=', $endDateTime));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'draft' => 0,
            'finalized' => 0,
            'amended' => 0,
            'archived' => 0,
            'other' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($status, $counts) && $status !== 'other' && $status !== 'total') {
                $counts[$status] += $aggregate;
            } else {
                $counts['other'] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (MedicalRecordModel $record): array => $record->toArray(),
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
