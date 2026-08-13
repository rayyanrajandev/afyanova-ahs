<?php

namespace App\Modules\EmergencyTriage\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseTransferRepositoryInterface;
use App\Modules\EmergencyTriage\Infrastructure\Models\EmergencyTriageCaseTransferModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentEmergencyTriageCaseTransferRepository implements EmergencyTriageCaseTransferRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $transfer = new EmergencyTriageCaseTransferModel();
        $transfer->fill($attributes);
        $transfer->save();

        return $transfer->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = EmergencyTriageCaseTransferModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $transfer = $query->find($id);

        return $transfer?->toArray();
    }

    public function findByCaseAndId(string $emergencyTriageCaseId, string $id): ?array
    {
        $query = EmergencyTriageCaseTransferModel::query()
            ->where('emergency_triage_case_id', $emergencyTriageCaseId);
        $this->applyPlatformScopeIfEnabled($query);
        $transfer = $query->where('id', $id)->first();

        return $transfer?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = EmergencyTriageCaseTransferModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $transfer = $query->find($id);
        if (! $transfer) {
            return null;
        }

        $transfer->fill($attributes);
        $transfer->save();

        return $transfer->toArray();
    }

    public function existsByTransferNumber(string $transferNumber): bool
    {
        return EmergencyTriageCaseTransferModel::query()
            ->where('transfer_number', $transferNumber)
            ->exists();
    }

    public function searchByCase(
        string $emergencyTriageCaseId,
        ?string $query,
        ?string $transferType,
        ?string $priority,
        ?string $status,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['transfer_number', 'transfer_type', 'priority', 'requested_at', 'status', 'destination_location', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'requested_at';

        $queryBuilder = EmergencyTriageCaseTransferModel::query()
            ->where('emergency_triage_case_id', $emergencyTriageCaseId);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('transfer_number', 'like', $like)
                        ->orWhere('source_location', 'like', $like)
                        ->orWhere('destination_location', 'like', $like)
                        ->orWhere('destination_facility_name', 'like', $like)
                        ->orWhere('status_reason', 'like', $like)
                        ->orWhere('clinical_handoff_notes', 'like', $like)
                        ->orWhere('transport_mode', 'like', $like);
                });
            })
            ->when($transferType, fn (Builder $builder, string $value) => $builder->where('transfer_type', $value))
            ->when($priority, fn (Builder $builder, string $value) => $builder->where('priority', $value))
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('requested_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('requested_at', '<=', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCountsByCase(
        string $emergencyTriageCaseId,
        ?string $query,
        ?string $transferType,
        ?string $priority,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = EmergencyTriageCaseTransferModel::query()
            ->where('emergency_triage_case_id', $emergencyTriageCaseId);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('transfer_number', 'like', $like)
                        ->orWhere('source_location', 'like', $like)
                        ->orWhere('destination_location', 'like', $like)
                        ->orWhere('destination_facility_name', 'like', $like)
                        ->orWhere('status_reason', 'like', $like)
                        ->orWhere('clinical_handoff_notes', 'like', $like)
                        ->orWhere('transport_mode', 'like', $like);
                });
            })
            ->when($transferType, fn (Builder $builder, string $value) => $builder->where('transfer_type', $value))
            ->when($priority, fn (Builder $builder, string $value) => $builder->where('priority', $value))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('requested_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('requested_at', '<=', $value));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'requested' => 0,
            'accepted' => 0,
            'in_transit' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'rejected' => 0,
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
                static fn (EmergencyTriageCaseTransferModel $transfer): array => $transfer->toArray(),
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
