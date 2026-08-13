<?php

namespace App\Modules\Platform\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Platform\Application\Exceptions\DuplicatePlatformRoleCodeException;
use App\Modules\Platform\Application\Exceptions\PlatformRoleProtectedException;
use App\Modules\Platform\Application\Exceptions\PrivilegedPlatformUserApprovalCaseException;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Application\Exceptions\UnknownPlatformRbacPermissionException;
use App\Modules\Platform\Application\Exceptions\UnknownPlatformRbacRoleException;
use App\Modules\Platform\Application\UseCases\BulkSyncPlatformUserRolesUseCase;
use App\Modules\Platform\Application\UseCases\CreatePlatformRoleUseCase;
use App\Modules\Platform\Application\UseCases\DeletePlatformRoleUseCase;
use App\Modules\Platform\Application\UseCases\GetPlatformRoleUseCase;
use App\Modules\Platform\Application\UseCases\ListPlatformPermissionsUseCase;
use App\Modules\Platform\Application\UseCases\ListPlatformRbacAuditLogsUseCase;
use App\Modules\Platform\Application\UseCases\ListPlatformRolesUseCase;
use App\Modules\Platform\Application\UseCases\SyncPlatformRolePermissionsUseCase;
use App\Modules\Platform\Application\UseCases\SyncPlatformUserRolesUseCase;
use App\Modules\Platform\Application\UseCases\UpdatePlatformRoleUseCase;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Presentation\Http\Requests\BulkSyncPlatformUserRolesRequest;
use App\Modules\Platform\Presentation\Http\Requests\StorePlatformRoleRequest;
use App\Modules\Platform\Presentation\Http\Requests\SyncPlatformRolePermissionsRequest;
use App\Modules\Platform\Presentation\Http\Requests\SyncPlatformUserRolesRequest;
use App\Modules\Platform\Presentation\Http\Requests\UpdatePlatformRoleRequest;
use App\Modules\Platform\Presentation\Http\Transformers\PlatformPermissionResponseTransformer;
use App\Modules\Platform\Presentation\Http\Transformers\PlatformRbacAuditLogResponseTransformer;
use App\Modules\Platform\Presentation\Http\Transformers\PlatformRoleResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformRbacController extends Controller
{
    public function permissions(Request $request, ListPlatformPermissionsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([PlatformPermissionResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function roles(
        Request $request,
        ListPlatformRolesUseCase $useCase,
        CurrentPlatformScopeContextInterface $scopeContext
    ): JsonResponse
    {
        $filters = $request->all();
        $restrictedToAssignableHospitalRoles = $this->shouldRestrictToAssignableHospitalRoles(
            $request->user(),
            $scopeContext,
        );

        if ($restrictedToAssignableHospitalRoles) {
            $filters['assignableOnly'] = true;
        }

        $result = $useCase->execute($filters);

        $result['meta']['roleAssignmentPolicy'] = $restrictedToAssignableHospitalRoles
            ? 'hospital_operational'
            : 'full';

        return response()->json([
            'data' => array_map([PlatformRoleResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function storeRole(StorePlatformRoleRequest $request, CreatePlatformRoleUseCase $useCase): JsonResponse
    {
        try {
            $role = $useCase->execute(
                payload: $this->toRolePersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicatePlatformRoleCodeException $exception) {
            return $this->validationError('code', $exception->getMessage());
        } catch (UnknownPlatformRbacPermissionException $exception) {
            return $this->validationError('permissionNames', $exception->getMessage());
        } catch (PlatformRoleProtectedException $exception) {
            return $this->validationError('permissionNames', $exception->getMessage());
        }

        return response()->json([
            'data' => PlatformRoleResponseTransformer::transform($role),
        ], 201);
    }

    public function role(string $id, GetPlatformRoleUseCase $useCase): JsonResponse
    {
        $role = $useCase->execute($id);
        abort_if($role === null, 404, 'Role not found.');

        return response()->json([
            'data' => PlatformRoleResponseTransformer::transform($role),
        ]);
    }

    public function updateRole(
        string $id,
        UpdatePlatformRoleRequest $request,
        UpdatePlatformRoleUseCase $useCase
    ): JsonResponse {
        try {
            $role = $useCase->execute(
                id: $id,
                payload: $this->toRolePersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicatePlatformRoleCodeException $exception) {
            return $this->validationError('code', $exception->getMessage());
        } catch (PlatformRoleProtectedException $exception) {
            return $this->validationError('role', $exception->getMessage());
        }

        abort_if($role === null, 404, 'Role not found.');

        return response()->json([
            'data' => PlatformRoleResponseTransformer::transform($role),
        ]);
    }

    public function deleteRole(string $id, Request $request, DeletePlatformRoleUseCase $useCase): JsonResponse
    {
        try {
            $deleted = $useCase->execute(
                id: $id,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PlatformRoleProtectedException $exception) {
            return $this->validationError('role', $exception->getMessage());
        }

        abort_if(! $deleted, 404, 'Role not found.');

        return response()->json([], 204);
    }

    public function syncRolePermissions(
        string $id,
        SyncPlatformRolePermissionsRequest $request,
        SyncPlatformRolePermissionsUseCase $useCase
    ): JsonResponse {
        try {
            $role = $useCase->execute(
                roleId: $id,
                permissionNames: $request->input('permissionNames', []),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PlatformRoleProtectedException $exception) {
            return $this->validationError('role', $exception->getMessage());
        } catch (UnknownPlatformRbacPermissionException $exception) {
            return $this->validationError('permissionNames', $exception->getMessage());
        }

        abort_if($role === null, 404, 'Role not found.');

        return response()->json([
            'data' => PlatformRoleResponseTransformer::transform($role),
        ]);
    }

    public function syncUserRoles(
        string $userId,
        SyncPlatformUserRolesRequest $request,
        SyncPlatformUserRolesUseCase $useCase
    ): JsonResponse {
        if (! ctype_digit($userId)) {
            return $this->validationError('userId', 'User id must be a numeric identifier.');
        }

        try {
            $result = $useCase->execute(
                userId: (int) $userId,
                roleIds: $request->input('roleIds', []),
                approvalCaseReference: $request->input('approvalCaseReference'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PrivilegedPlatformUserApprovalCaseException $exception) {
            return $this->validationError('approvalCaseReference', $exception->getMessage());
        } catch (UnknownPlatformRbacRoleException $exception) {
            return $this->validationError('roleIds', $exception->getMessage());
        }

        abort_if($result === null, 404, 'User not found.');

        return response()->json([
            'data' => [
                'userId' => $result['user_id'] ?? null,
                'roleIds' => $result['role_ids'] ?? [],
                'roles' => array_map([PlatformRoleResponseTransformer::class, 'transform'], $result['roles'] ?? []),
            ],
        ]);
    }

    public function bulkSyncUserRoles(
        BulkSyncPlatformUserRolesRequest $request,
        BulkSyncPlatformUserRolesUseCase $useCase
    ): JsonResponse {
        try {
            $result = $useCase->execute(
                userIds: array_map('intval', (array) ($request->validated()['userIds'] ?? [])),
                roleIds: array_values(array_map('strval', (array) ($request->validated()['roleIds'] ?? []))),
                approvalCaseReference: $request->input('approvalCaseReference'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PrivilegedPlatformUserApprovalCaseException $exception) {
            return $this->validationError('approvalCaseReference', $exception->getMessage());
        } catch (UnknownPlatformRbacRoleException $exception) {
            return $this->validationError('roleIds', $exception->getMessage());
        }

        return response()->json([
            'data' => [
                'requestedCount' => (int) ($result['requested_count'] ?? 0),
                'updatedCount' => (int) ($result['updated_count'] ?? 0),
                'skippedUserIds' => array_values(array_map('intval', (array) ($result['skipped_user_ids'] ?? []))),
                'updates' => array_map(static fn (array $update): array => [
                    'userId' => isset($update['user_id']) ? (int) $update['user_id'] : null,
                    'roleIds' => array_values(array_map('strval', (array) ($update['role_ids'] ?? []))),
                    'roles' => array_map(
                        [PlatformRoleResponseTransformer::class, 'transform'],
                        (array) ($update['roles'] ?? []),
                    ),
                ], (array) ($result['updates'] ?? [])),
            ],
        ]);
    }

    public function auditLogs(Request $request, ListPlatformRbacAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([PlatformRbacAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
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

    private function shouldRestrictToAssignableHospitalRoles(
        ?User $actor,
        CurrentPlatformScopeContextInterface $scopeContext
    ): bool
    {
        if (! $actor) {
            return true;
        }

        // Platform-only: a facility-scoped admin must be restricted to
        // assignable-hospital roles, not shown every platform-wide role — see
        // RBAC_Remediation_Plan.md Phase 2.
        if ($actor->isPlatformSuperAdminAccess() || $actor->hasPermissionTo('platform.rbac.manage-roles')) {
            return false;
        }

        return $scopeContext->hasFacility()
            || $this->actorHasActiveFacilityAssignment((int) $actor->id);
    }

    private function actorHasActiveFacilityAssignment(int $actorId): bool
    {
        return DB::table('facility_user')
            ->where('user_id', $actorId)
            ->where('is_active', true)
            ->exists();
    }

    private function isAssignableHospitalRoleCode(mixed $roleCode): bool
    {
        $code = strtoupper(trim((string) $roleCode));

        if (str_starts_with($code, 'PLATFORM.') || str_contains($code, 'SUPER.ADMIN')) {
            return false;
        }

        if ($code === 'ADMIN.FACILITY') {
            return false;
        }

        $allowedPrefixes = [
            'ADMIN.', 'CLINICAL.', 'FINANCE.',
            'LAB.', 'RADIOLOGY.', 'PHARMACY.', 'THEATRE.',
            'INVENTORY.',
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        // Legacy backward compatibility
        return str_starts_with($code, 'HOSPITAL.')
            && $code !== 'HOSPITAL.FACILITY.ADMIN';
    }

    private function toRolePersistencePayload(array $validated): array
    {
        $fieldMap = [
            'code' => 'code',
            'name' => 'name',
            'status' => 'status',
            'description' => 'description',
            'permissionNames' => 'permission_names',
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
