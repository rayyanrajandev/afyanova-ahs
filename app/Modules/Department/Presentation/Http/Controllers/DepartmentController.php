<?php

namespace App\Modules\Department\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Department\Application\Exceptions\DuplicateDepartmentCodeException;
use App\Modules\Department\Application\UseCases\CreateDepartmentUseCase;
use App\Modules\Department\Application\UseCases\GetDepartmentUseCase;
use App\Modules\Department\Application\UseCases\ListDepartmentAuditLogsUseCase;
use App\Modules\Department\Application\UseCases\ListDepartmentsUseCase;
use App\Modules\Department\Application\UseCases\ListDepartmentStatusCountsUseCase;
use App\Modules\Department\Application\UseCases\UpdateDepartmentStatusUseCase;
use App\Modules\Department\Application\UseCases\UpdateDepartmentUseCase;
use App\Modules\Department\Presentation\Http\Requests\StoreDepartmentRequest;
use App\Modules\Department\Presentation\Http\Requests\UpdateDepartmentRequest;
use App\Modules\Department\Presentation\Http\Requests\UpdateDepartmentStatusRequest;
use App\Modules\Department\Presentation\Http\Transformers\DepartmentAuditLogResponseTransformer;
use App\Modules\Department\Presentation\Http\Transformers\DepartmentResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepartmentController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListDepartmentsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => $this->transformDepartments($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListDepartmentStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function store(StoreDepartmentRequest $request, CreateDepartmentUseCase $useCase): JsonResponse
    {
        try {
            $department = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateDepartmentCodeException $exception) {
            return $this->validationError('code', $exception->getMessage());
        }

        return response()->json([
            'data' => $this->transformDepartment($department),
        ], 201);
    }

    public function show(string $id, GetDepartmentUseCase $useCase): JsonResponse
    {
        $department = $useCase->execute($id);
        abort_if($department === null, 404, 'Department not found.');

        return response()->json([
            'data' => $this->transformDepartment($department),
        ]);
    }

    public function update(string $id, UpdateDepartmentRequest $request, UpdateDepartmentUseCase $useCase): JsonResponse
    {
        try {
            $department = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateDepartmentCodeException $exception) {
            return $this->validationError('code', $exception->getMessage());
        }

        abort_if($department === null, 404, 'Department not found.');

        return response()->json([
            'data' => $this->transformDepartment($department),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateDepartmentStatusRequest $request,
        UpdateDepartmentStatusUseCase $useCase
    ): JsonResponse {
        try {
            $department = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($department === null, 404, 'Department not found.');

        return response()->json([
            'data' => $this->transformDepartment($department),
        ]);
    }

    public function auditLogs(string $id, Request $request, ListDepartmentAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(departmentId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Department not found.');

        return response()->json([
            'data' => array_map([DepartmentAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListDepartmentAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            departmentId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Department not found.');

        $safeId = $this->safeExportIdentifier($id, 'department');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('department_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    departmentId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }

    private function validationError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => 'VALIDATION_ERROR',
            'errors' => [
                $field => [$message],
            ],
        ], 422);
    }

    private function tenantScopeRequiredError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
    }

    private function transformDepartments(array $departments): array
    {
        $rows = $this->attachManagerContext($departments);

        return array_map([DepartmentResponseTransformer::class, 'transform'], $rows);
    }

    private function transformDepartment(array $department): array
    {
        $rows = $this->attachManagerContext([$department]);

        return DepartmentResponseTransformer::transform($rows[0] ?? $department);
    }

    private function attachManagerContext(array $departments): array
    {
        $managerUserIds = array_values(array_unique(array_filter(array_map(
            static fn (array $department): int => (int) ($department['manager_user_id'] ?? 0),
            $departments,
        ))));

        if ($managerUserIds === []) {
            return array_map(static function (array $department): array {
                $department['manager'] = null;

                return $department;
            }, $departments);
        }

        $users = User::query()
            ->whereIn('id', $managerUserIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $staffProfiles = StaffProfileModel::query()
            ->whereIn('user_id', $managerUserIds)
            ->get(['id', 'user_id', 'status'])
            ->keyBy('user_id');

        return array_map(static function (array $department) use ($users, $staffProfiles): array {
            $managerUserId = (int) ($department['manager_user_id'] ?? 0);

            if ($managerUserId <= 0) {
                $department['manager'] = null;

                return $department;
            }

            $user = $users->get($managerUserId);
            $staffProfile = $staffProfiles->get($managerUserId);

            $department['manager'] = [
                'user_id' => $managerUserId,
                'display_name' => trim((string) ($user?->name ?? '')) ?: null,
                'email' => trim((string) ($user?->email ?? '')) ?: null,
                'staff_profile_id' => $staffProfile?->id ? (string) $staffProfile->id : null,
                'staff_status' => trim((string) ($staffProfile?->status ?? '')) ?: null,
            ];

            return $department;
        }, $departments);
    }

    private function toPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'code' => 'code',
            'name' => 'name',
            'serviceType' => 'service_type',
            'isPatientFacing' => 'is_patient_facing',
            'isAppointmentable' => 'is_appointmentable',
            'managerUserId' => 'manager_user_id',
            'description' => 'description',
        ];

        $payload = [];
        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }
            $payload[$storageKey] = $validated[$requestKey];
        }

        return $payload;
    }
}



