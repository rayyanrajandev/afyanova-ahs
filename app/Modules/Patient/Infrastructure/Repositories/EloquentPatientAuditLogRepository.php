<?php

namespace App\Modules\Patient\Infrastructure\Repositories;

use App\Modules\Patient\Domain\Repositories\PatientAuditLogRepositoryInterface;
use App\Modules\Patient\Infrastructure\Models\PatientAuditLogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentPatientAuditLogRepository implements PatientAuditLogRepositoryInterface
{
    public function write(
        string $patientId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void {
        PatientAuditLogModel::query()->create([
            'patient_id' => $patientId,
            'action' => $action,
            'actor_id' => $actorId,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function listByPatientId(
        string $patientId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
    ): array {
        $paginator = PatientAuditLogModel::query()
            ->leftJoin('users', 'users.id', '=', 'patient_audit_logs.actor_id')
            ->where('patient_audit_logs.patient_id', $patientId)
            ->when(
                $query,
                fn (Builder $builder, string $value) => $builder->whereRaw(
                    'LOWER(patient_audit_logs.action) LIKE ?',
                    ['%'.strtolower($value).'%']
                )
            )
            ->when($action, fn (Builder $builder, string $value) => $builder->where('patient_audit_logs.action', $value))
            ->when($actorType === 'system', fn (Builder $builder) => $builder->whereNull('patient_audit_logs.actor_id'))
            ->when($actorType === 'user', fn (Builder $builder) => $builder->whereNotNull('patient_audit_logs.actor_id'))
            ->when($actorId !== null, fn (Builder $builder) => $builder->where('patient_audit_logs.actor_id', $actorId))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('patient_audit_logs.created_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('patient_audit_logs.created_at', '<=', $value))
            ->orderByDesc('patient_audit_logs.created_at')
            ->paginate(
                perPage: $perPage,
                columns: [
                    'patient_audit_logs.*',
                    'users.name as actor_name',
                    'users.email as actor_email',
                ],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult($paginator);
    }

    private function toPagedResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (PatientAuditLogModel $log): array => $log->toArray(),
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
