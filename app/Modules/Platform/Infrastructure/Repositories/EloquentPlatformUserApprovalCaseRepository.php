<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Models\User;
use App\Modules\Platform\Domain\Repositories\PlatformUserApprovalCaseRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Models\PlatformUserApprovalCaseAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\PlatformUserApprovalCaseCommentModel;
use App\Modules\Platform\Infrastructure\Models\PlatformUserApprovalCaseModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EloquentPlatformUserApprovalCaseRepository implements PlatformUserApprovalCaseRepositoryInterface
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
    ) {}

    public function searchCases(
        ?string $query,
        ?string $status,
        ?string $actionType,
        ?int $targetUserId,
        ?int $requesterUserId,
        ?int $reviewerUserId,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, [
            'case_reference',
            'status',
            'action_type',
            'submitted_at',
            'decided_at',
            'created_at',
            'updated_at',
        ], true)
            ? $sortBy
            : 'created_at';

        $queryBuilder = PlatformUserApprovalCaseModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $value): void {
                $like = '%'.strtolower($value).'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->whereRaw('LOWER(case_reference) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(decision_reason) LIKE ?', [$like]);
                });
            })
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($actionType, fn (Builder $builder, string $value) => $builder->where('action_type', $value))
            ->when($targetUserId !== null, fn (Builder $builder) => $builder->where('target_user_id', $targetUserId))
            ->when($requesterUserId !== null, fn (Builder $builder) => $builder->where('requester_user_id', $requesterUserId))
            ->when($reviewerUserId !== null, fn (Builder $builder) => $builder->where('reviewer_user_id', $reviewerUserId))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('created_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('created_at', '<=', $value))
            ->orderBy($sortBy, $sortDirection)
            ->orderByDesc('id');

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toPagedResult(
            $paginator,
            static fn (PlatformUserApprovalCaseModel $case): array => array_merge($case->toArray(), [
                'comments' => [],
            ]),
        );
    }

    public function findCaseById(string $id): ?array
    {
        $query = PlatformUserApprovalCaseModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $case = $query->find($id);
        if (! $case) {
            return null;
        }

        return $this->withCaseDetails($case->toArray());
    }

    public function findCaseByReferenceInTenant(string $tenantId, string $caseReference, ?string $excludeCaseId = null): ?array
    {
        $query = PlatformUserApprovalCaseModel::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(case_reference) = ?', [strtolower(trim($caseReference))]);

        if ($excludeCaseId !== null) {
            $query->where('id', '!=', $excludeCaseId);
        }

        return $query->first()?->toArray();
    }

    public function resolveFacilityInScope(string $facilityId): ?array
    {
        $query = DB::table('facilities')
            ->where('id', $facilityId);

        if ($this->isPlatformScopingEnabled()) {
            $this->platformScopeQueryApplier->apply($query, tenantColumn: 'tenant_id', facilityColumn: 'id');
        }

        $row = $query->first(['id', 'tenant_id', 'code', 'name']);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'tenant_id' => (string) $row->tenant_id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
        ];
    }

    public function resolveUserInScope(int $userId): ?array
    {
        $query = User::query()->where('id', $userId);
        $this->applyUserScopeIfEnabled($query);

        $user = $query->first(['id', 'tenant_id', 'status', 'name', 'email']);
        if ($user === null) {
            return null;
        }

        return $user->toArray();
    }

    public function createCase(array $attributes): array
    {
        $case = new PlatformUserApprovalCaseModel();
        $case->fill($attributes);
        $case->save();

        return $case->toArray();
    }

    public function updateCase(string $id, array $attributes): ?array
    {
        $query = PlatformUserApprovalCaseModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $case = $query->find($id);
        if (! $case) {
            return null;
        }

        $case->fill($attributes);
        $case->save();

        return $case->toArray();
    }

    public function createComment(string $approvalCaseId, array $attributes): array
    {
        $comment = new PlatformUserApprovalCaseCommentModel();
        $comment->fill(array_merge($attributes, [
            'approval_case_id' => $approvalCaseId,
        ]));
        $comment->save();

        return $comment->toArray();
    }

    public function listComments(string $approvalCaseId): array
    {
        return PlatformUserApprovalCaseCommentModel::query()
            ->where('approval_case_id', $approvalCaseId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (PlatformUserApprovalCaseCommentModel $comment): array => $comment->toArray())
            ->all();
    }

    public function writeAuditLog(
        string $approvalCaseId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void {
        PlatformUserApprovalCaseAuditLogModel::query()->create([
            'approval_case_id' => $approvalCaseId,
            'actor_id' => $actorId,
            'action' => $action,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function listAuditLogs(
        string $approvalCaseId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $paginator = PlatformUserApprovalCaseAuditLogModel::query()
            ->where('approval_case_id', $approvalCaseId)
            ->when($query, fn (Builder $builder, string $value) => $builder->whereRaw('LOWER(action) LIKE ?', ['%'.strtolower($value).'%']))
            ->when($action, fn (Builder $builder, string $value) => $builder->where('action', $value))
            ->when($actorType === 'system', fn (Builder $builder) => $builder->whereNull('actor_id'))
            ->when($actorType === 'user', fn (Builder $builder) => $builder->whereNotNull('actor_id'))
            ->when($actorId !== null, fn (Builder $builder) => $builder->where('actor_id', $actorId))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('created_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('created_at', '<=', $value))
            ->orderByDesc('created_at')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult(
            $paginator,
            static fn (PlatformUserApprovalCaseAuditLogModel $auditLog): array => $auditLog->toArray(),
        );
    }

    /**
     * @template T
     * @param  callable(T): array<string, mixed>  $mapper
     */
    private function toPagedResult(LengthAwarePaginator $paginator, callable $mapper): array
    {
        return [
            'data' => array_map($mapper, $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function withCaseDetails(array $case): array
    {
        $approvalCaseId = (string) ($case['id'] ?? '');
        $case['comments'] = $this->listComments($approvalCaseId);

        return $case;
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function applyUserScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $tenantId = $this->platformScopeContext->tenantId();
        if ($tenantId !== null) {
            $query->where('users.tenant_id', $tenantId);

            return;
        }

        $facilityId = $this->platformScopeContext->facilityId();
        if ($facilityId !== null) {
            $query->whereExists(function ($queryBuilder) use ($facilityId): void {
                $queryBuilder
                    ->selectRaw('1')
                    ->from('facility_user')
                    ->whereColumn('facility_user.user_id', 'users.id')
                    ->where('facility_user.facility_id', $facilityId);
            });
        }
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}

