<?php

namespace App\Modules\Billing\Application\Support;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Database\Eloquent\Builder;

class BillingCatalogDepartmentResolver
{
    /** @var array<string, array<string, mixed>> */
    private array $departmentCache = [];

    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
    ) {}

    public function resolveDepartmentName(string $departmentId): ?string
    {
        $result = $this->resolve(['department_id' => $departmentId]);

        return $result['department'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolve(array $payload): array
    {
        $hasDepartmentId = array_key_exists('department_id', $payload);
        $hasDepartment = array_key_exists('department', $payload);

        if (! $hasDepartmentId && ! $hasDepartment) {
            return [];
        }

        if ($hasDepartmentId) {
            $departmentId = $this->nullableTrimmedValue($payload['department_id'] ?? null);

            if ($departmentId === null) {
                return [
                    'department_id' => null,
                    'department' => null,
                ];
            }

            $department = $this->findByIdInScope($departmentId);
            if ($department === null) {
                throw new \InvalidArgumentException('Selected department was not found in the current hospital scope.');
            }

            return [
                'department_id' => $department['id'] ?? null,
                'department' => $this->nullableTrimmedValue($department['name'] ?? null),
            ];
        }

        $departmentLabel = $this->nullableTrimmedValue($payload['department'] ?? null);
        if ($departmentLabel === null) {
            return [
                'department_id' => null,
                'department' => null,
            ];
        }

        $department = $this->findByLabelInScope($departmentLabel);

        if ($department === null) {
            return [
                'department_id' => null,
                'department' => $departmentLabel,
            ];
        }

        return [
            'department_id' => $department['id'] ?? null,
            'department' => $this->nullableTrimmedValue($department['name'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByIdInScope(string $departmentId): ?array
    {
        if (isset($this->departmentCache[$departmentId])) {
            return $this->departmentCache[$departmentId];
        }

        $query = DepartmentModel::query()->where('id', $departmentId);
        $this->applyScope($query);

        $result = $query->first()?->toArray();
        $this->departmentCache[$departmentId] = $result;

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByLabelInScope(string $departmentLabel): ?array
    {
        foreach ($this->departmentLookupCandidates($departmentLabel) as $candidate) {
            $department = $this->findByNameCandidate($candidate);
            if ($department !== null) {
                return $department;
            }

            $department = $this->findByCodeCandidate($candidate);
            if ($department !== null) {
                return $department;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByNameCandidate(string $candidate): ?array
    {
        $query = DepartmentModel::query()->whereRaw('LOWER(name) = ?', [$candidate]);
        $this->applyScope($query);

        return $query->orderBy('name')->first()?->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByCodeCandidate(string $candidate): ?array
    {
        $query = DepartmentModel::query()->whereRaw('LOWER(code) = ?', [$candidate]);
        $this->applyScope($query);

        return $query->orderBy('name')->first()?->toArray();
    }

    /**
     * @return array<int, string>
     */
    private function departmentLookupCandidates(string $departmentLabel): array
    {
        $normalized = strtolower(trim($departmentLabel));
        if ($normalized === '') {
            return [];
        }

        $aliases = [
            'outpatient' => ['general opd', 'opd'],
            'general outpatient' => ['general opd', 'opd'],
        ];

        return array_values(array_unique([
            $normalized,
            ...($aliases[$normalized] ?? []),
        ]));
    }

    private function applyScope(Builder $query): void
    {
        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();

        if ($tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $tenantId);
        }

        if ($facilityId === null) {
            $query->whereNull('facility_id');
        } else {
            $query->where('facility_id', $facilityId);
        }
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
