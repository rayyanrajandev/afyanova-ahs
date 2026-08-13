<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            $table->uuid('department_id')->nullable()->after('service_type');
            $table->index(['department_id', 'status'], 'billing_service_catalog_items_department_id_status_index');
            $table->foreign('department_id', 'billing_service_catalog_items_department_id_foreign')
                ->references('id')
                ->on('departments')
                ->nullOnDelete();
        });

        $this->backfillDepartmentMappings();
    }

    public function down(): void
    {
        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            $table->dropForeign('billing_service_catalog_items_department_id_foreign');
            $table->dropIndex('billing_service_catalog_items_department_id_status_index');
            $table->dropColumn('department_id');
        });
    }

    private function backfillDepartmentMappings(): void
    {
        $rows = DB::table('billing_service_catalog_items')
            ->select('id', 'tenant_id', 'facility_id', 'department')
            ->whereNull('department_id')
            ->orderBy('created_at')
            ->get();

        foreach ($rows as $row) {
            $department = $this->resolveDepartment(
                tenantId: $row->tenant_id,
                facilityId: $row->facility_id,
                departmentLabel: $row->department,
            );

            if ($department === null) {
                continue;
            }

            DB::table('billing_service_catalog_items')
                ->where('id', $row->id)
                ->update([
                    'department_id' => $department->id,
                    'department' => $department->name,
                    'updated_at' => now(),
                ]);
        }
    }

    private function resolveDepartment(?string $tenantId, ?string $facilityId, ?string $departmentLabel): ?object
    {
        $normalized = $this->normalizeLabel($departmentLabel);
        if ($normalized === null) {
            return null;
        }

        foreach ($this->departmentLookupCandidates($normalized) as $candidate) {
            $department = $this->findDepartmentByName($tenantId, $facilityId, $candidate);
            if ($department !== null) {
                return $department;
            }

            $department = $this->findDepartmentByCode($tenantId, $facilityId, $candidate);
            if ($department !== null) {
                return $department;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function departmentLookupCandidates(string $normalizedLabel): array
    {
        $aliases = [
            'outpatient' => ['general opd', 'opd'],
            'general outpatient' => ['general opd', 'opd'],
        ];

        return array_values(array_unique([
            $normalizedLabel,
            ...($aliases[$normalizedLabel] ?? []),
        ]));
    }

    private function normalizeLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return $normalized === '' ? null : $normalized;
    }

    private function findDepartmentByName(?string $tenantId, ?string $facilityId, string $candidate): ?object
    {
        $query = DB::table('departments')
            ->select('id', 'name')
            ->whereRaw('LOWER(name) = ?', [$candidate]);

        $this->applyScope($query, $tenantId, $facilityId);

        return $query->orderBy('name')->first();
    }

    private function findDepartmentByCode(?string $tenantId, ?string $facilityId, string $candidate): ?object
    {
        $query = DB::table('departments')
            ->select('id', 'name')
            ->whereRaw('LOWER(code) = ?', [$candidate]);

        $this->applyScope($query, $tenantId, $facilityId);

        return $query->orderBy('name')->first();
    }

    private function applyScope(Builder $query, ?string $tenantId, ?string $facilityId): void
    {
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
};
