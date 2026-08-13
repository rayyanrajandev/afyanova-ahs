<?php

namespace App\Modules\Staff\Infrastructure\Repositories;

use App\Modules\Staff\Domain\Repositories\StaffCredentialingAuditLogRepositoryInterface;
use App\Modules\Staff\Infrastructure\Models\StaffCredentialingAuditLogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentStaffCredentialingAuditLogRepository implements StaffCredentialingAuditLogRepositoryInterface
{
    public function write(
        string $staffProfileId,
        ?string $tenantId,
        ?string $staffRegulatoryProfileId,
        ?string $staffProfessionalRegistrationId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = [],
    ): void {
        StaffCredentialingAuditLogModel::query()->create([
            'staff_profile_id' => $staffProfileId,
            'tenant_id' => $tenantId,
            'staff_regulatory_profile_id' => $staffRegulatoryProfileId,
            'staff_professional_registration_id' => $staffProfessionalRegistrationId,
            'action' => $action,
            'actor_id' => $actorId,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function listByStaffProfileId(
        string $staffProfileId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
    ): array {
        $paginator = StaffCredentialingAuditLogModel::query()
            ->where('staff_profile_id', $staffProfileId)
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

        return $this->toPagedResult($paginator);
    }

    private function toPagedResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (StaffCredentialingAuditLogModel $log): array => $log->toArray(),
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
