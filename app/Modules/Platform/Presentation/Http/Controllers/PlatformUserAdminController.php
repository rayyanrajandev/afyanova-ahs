<?php

namespace App\Modules\Platform\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\DuplicatePlatformUserEmailException;
use App\Modules\Platform\Application\Exceptions\InvalidPlatformUserFacilityAssignmentsException;
use App\Modules\Platform\Application\Exceptions\PasswordResetDispatchFailedException;
use App\Modules\Platform\Application\Exceptions\PrivilegedPlatformUserApprovalCaseException;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Application\Exceptions\UnknownPlatformUserFacilityException;
use App\Modules\Platform\Application\UseCases\BulkDispatchPlatformUserCredentialLinksUseCase;
use App\Modules\Platform\Application\UseCases\BulkSyncPlatformUserFacilitiesUseCase;
use App\Modules\Platform\Application\UseCases\BulkUpdatePlatformUserStatusUseCase;
use App\Modules\Platform\Application\UseCases\CreatePlatformUserUseCase;
use App\Modules\Platform\Application\UseCases\GetPlatformUserUseCase;
use App\Modules\Platform\Application\UseCases\ListPlatformUserAdminAuditLogsUseCase;
use App\Modules\Platform\Application\UseCases\ListPlatformUserStatusCountsUseCase;
use App\Modules\Platform\Application\UseCases\ListPlatformUsersUseCase;
use App\Modules\Platform\Application\UseCases\SendPlatformUserInviteLinkUseCase;
use App\Modules\Platform\Application\UseCases\SendPlatformUserPasswordResetLinkUseCase;
use App\Modules\Platform\Application\UseCases\SyncPlatformUserFacilitiesUseCase;
use App\Modules\Platform\Application\UseCases\UpdatePlatformUserStatusUseCase;
use App\Modules\Platform\Application\UseCases\UpdatePlatformUserUseCase;
use App\Modules\Platform\Presentation\Http\Requests\BulkDispatchPlatformUserCredentialLinksRequest;
use App\Modules\Platform\Presentation\Http\Requests\BulkSyncPlatformUserFacilitiesRequest;
use App\Modules\Platform\Presentation\Http\Requests\BulkUpdatePlatformUserStatusRequest;
use App\Modules\Platform\Presentation\Http\Requests\StorePlatformUserRequest;
use App\Modules\Platform\Presentation\Http\Requests\SyncPlatformUserFacilitiesRequest;
use App\Modules\Platform\Presentation\Http\Requests\UpdatePlatformUserRequest;
use App\Modules\Platform\Presentation\Http\Requests\UpdatePlatformUserStatusRequest;
use App\Modules\Platform\Presentation\Http\Transformers\PlatformUserAdminAuditLogResponseTransformer;
use App\Modules\Platform\Presentation\Http\Transformers\PlatformUserResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformUserAdminController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListPlatformUsersUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([PlatformUserResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListPlatformUserStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function show(string $id, GetPlatformUserUseCase $useCase): JsonResponse
    {
        if (! ctype_digit($id)) {
            return $this->validationError('id', 'User id must be a numeric identifier.');
        }

        $user = $useCase->execute((int) $id);
        abort_if($user === null, 404, 'User not found.');

        return response()->json([
            'data' => PlatformUserResponseTransformer::transform($user),
        ]);
    }

    public function store(StorePlatformUserRequest $request, CreatePlatformUserUseCase $useCase): JsonResponse
    {
        try {
            $user = $useCase->execute(
                payload: $this->toUserPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicatePlatformUserEmailException $exception) {
            return $this->validationError('email', $exception->getMessage());
        }

        return response()->json([
            'data' => PlatformUserResponseTransformer::transform($user),
        ], 201);
    }

    public function update(
        string $id,
        UpdatePlatformUserRequest $request,
        UpdatePlatformUserUseCase $useCase
    ): JsonResponse {
        if (! ctype_digit($id)) {
            return $this->validationError('id', 'User id must be a numeric identifier.');
        }

        try {
            $user = $useCase->execute(
                id: (int) $id,
                payload: $this->toUserPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PrivilegedPlatformUserApprovalCaseException $exception) {
            return $this->validationError('approvalCaseReference', $exception->getMessage());
        } catch (DuplicatePlatformUserEmailException $exception) {
            return $this->validationError('email', $exception->getMessage());
        }

        abort_if($user === null, 404, 'User not found.');

        return response()->json([
            'data' => PlatformUserResponseTransformer::transform($user),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdatePlatformUserStatusRequest $request,
        UpdatePlatformUserStatusUseCase $useCase
    ): JsonResponse {
        if (! ctype_digit($id)) {
            return $this->validationError('id', 'User id must be a numeric identifier.');
        }

        try {
            $user = $useCase->execute(
                id: (int) $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                approvalCaseReference: $request->input('approvalCaseReference'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PrivilegedPlatformUserApprovalCaseException $exception) {
            return $this->validationError('approvalCaseReference', $exception->getMessage());
        }

        abort_if($user === null, 404, 'User not found.');

        return response()->json([
            'data' => PlatformUserResponseTransformer::transform($user),
        ]);
    }

    public function bulkUpdateStatus(
        BulkUpdatePlatformUserStatusRequest $request,
        BulkUpdatePlatformUserStatusUseCase $useCase
    ): JsonResponse {
        try {
            $result = $useCase->execute(
                userIds: array_map('intval', (array) ($request->validated()['userIds'] ?? [])),
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                approvalCaseReference: $request->input('approvalCaseReference'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PrivilegedPlatformUserApprovalCaseException $exception) {
            return $this->validationError('approvalCaseReference', $exception->getMessage());
        }

        return response()->json([
            'data' => [
                'requestedCount' => (int) ($result['requested_count'] ?? 0),
                'updatedCount' => (int) ($result['updated_count'] ?? 0),
                'skippedUserIds' => array_values(array_map('intval', (array) ($result['skipped_user_ids'] ?? []))),
                'users' => array_map(
                    [PlatformUserResponseTransformer::class, 'transform'],
                    (array) ($result['users'] ?? []),
                ),
            ],
        ]);
    }

    public function bulkSendCredentialLinks(
        BulkDispatchPlatformUserCredentialLinksRequest $request,
        BulkDispatchPlatformUserCredentialLinksUseCase $useCase
    ): JsonResponse {
        try {
            $result = $useCase->execute(
                userIds: array_map('intval', (array) ($request->validated()['userIds'] ?? [])),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        return response()->json([
            'data' => [
                'requestedCount' => (int) ($result['requested_count'] ?? 0),
                'dispatchedCount' => (int) ($result['dispatched_count'] ?? 0),
                'inviteCount' => (int) ($result['invite_count'] ?? 0),
                'resetCount' => (int) ($result['reset_count'] ?? 0),
                'skippedUserIds' => array_values(array_map('intval', (array) ($result['skipped_user_ids'] ?? []))),
                'failedCount' => (int) ($result['failed_count'] ?? 0),
                'failedUserIds' => array_values(array_map('intval', (array) ($result['failed_user_ids'] ?? []))),
                'failed' => array_map(static fn (array $failure): array => [
                    'userId' => isset($failure['user_id']) ? (int) $failure['user_id'] : null,
                    'message' => isset($failure['message']) ? (string) $failure['message'] : 'Dispatch failed.',
                ], (array) ($result['failed'] ?? [])),
            ],
        ]);
    }

    public function syncFacilities(
        string $id,
        SyncPlatformUserFacilitiesRequest $request,
        SyncPlatformUserFacilitiesUseCase $useCase
    ): JsonResponse {
        if (! ctype_digit($id)) {
            return $this->validationError('id', 'User id must be a numeric identifier.');
        }

        try {
            $user = $useCase->execute(
                userId: (int) $id,
                facilityAssignments: $this->toFacilityAssignmentPayload($request->validated()),
                approvalCaseReference: $request->input('approvalCaseReference'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PrivilegedPlatformUserApprovalCaseException $exception) {
            return $this->validationError('approvalCaseReference', $exception->getMessage());
        } catch (UnknownPlatformUserFacilityException|InvalidPlatformUserFacilityAssignmentsException $exception) {
            return $this->validationError('facilityAssignments', $exception->getMessage());
        }

        abort_if($user === null, 404, 'User not found.');

        return response()->json([
            'data' => PlatformUserResponseTransformer::transform($user),
        ]);
    }

    public function bulkSyncFacilities(
        BulkSyncPlatformUserFacilitiesRequest $request,
        BulkSyncPlatformUserFacilitiesUseCase $useCase
    ): JsonResponse {
        try {
            $result = $useCase->execute(
                userIds: array_map('intval', (array) ($request->validated()['userIds'] ?? [])),
                facilityAssignments: $this->toFacilityAssignmentPayload($request->validated()),
                approvalCaseReference: $request->input('approvalCaseReference'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PrivilegedPlatformUserApprovalCaseException $exception) {
            return $this->validationError('approvalCaseReference', $exception->getMessage());
        } catch (UnknownPlatformUserFacilityException|InvalidPlatformUserFacilityAssignmentsException $exception) {
            return $this->validationError('facilityAssignments', $exception->getMessage());
        }

        return response()->json([
            'data' => [
                'requestedCount' => (int) ($result['requested_count'] ?? 0),
                'updatedCount' => (int) ($result['updated_count'] ?? 0),
                'skippedUserIds' => array_values(array_map('intval', (array) ($result['skipped_user_ids'] ?? []))),
                'users' => array_map(
                    [PlatformUserResponseTransformer::class, 'transform'],
                    (array) ($result['users'] ?? []),
                ),
            ],
        ]);
    }

    public function sendPasswordResetLink(
        string $id,
        Request $request,
        SendPlatformUserPasswordResetLinkUseCase $useCase
    ): JsonResponse {
        if (! ctype_digit($id)) {
            return $this->validationError('id', 'User id must be a numeric identifier.');
        }

        try {
            $result = $useCase->execute(
                userId: (int) $id,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PasswordResetDispatchFailedException $exception) {
            return $this->validationError('passwordReset', $exception->getMessage());
        }

        abort_if($result === null, 404, 'User not found.');

        return response()->json([
            'data' => [
                'userId' => $result['user_id'] ?? null,
                'message' => $result['message'] ?? null,
                'previewUrl' => $result['preview_url'] ?? null,
                'deliveryMode' => $result['delivery_mode'] ?? null,
            ],
        ]);
    }

    public function sendInviteLink(
        string $id,
        Request $request,
        SendPlatformUserInviteLinkUseCase $useCase
    ): JsonResponse {
        if (! ctype_digit($id)) {
            return $this->validationError('id', 'User id must be a numeric identifier.');
        }

        try {
            $result = $useCase->execute(
                userId: (int) $id,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PasswordResetDispatchFailedException $exception) {
            return $this->validationError('inviteLink', $exception->getMessage());
        }

        abort_if($result === null, 404, 'User not found.');

        return response()->json([
            'data' => [
                'userId' => $result['user_id'] ?? null,
                'message' => $result['message'] ?? null,
                'previewUrl' => $result['preview_url'] ?? null,
                'deliveryMode' => $result['delivery_mode'] ?? null,
            ],
        ]);
    }

    public function auditLogs(
        string $id,
        Request $request,
        GetPlatformUserUseCase $getUserUseCase,
        ListPlatformUserAdminAuditLogsUseCase $useCase
    ): JsonResponse {
        if (! ctype_digit($id)) {
            return $this->validationError('id', 'User id must be a numeric identifier.');
        }

        $user = $getUserUseCase->execute((int) $id);
        abort_if($user === null, 404, 'User not found.');

        $result = $useCase->execute((int) $id, $request->all());

        return response()->json([
            'data' => array_map([PlatformUserAdminAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        GetPlatformUserUseCase $getUserUseCase,
        ListPlatformUserAdminAuditLogsUseCase $useCase
    ): JsonResponse|StreamedResponse {
        if (! ctype_digit($id)) {
            return $this->validationError('id', 'User id must be a numeric identifier.');
        }

        $targetUserId = (int) $id;
        $user = $getUserUseCase->execute($targetUserId);
        abort_if($user === null, 404, 'User not found.');

        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute($targetUserId, $filters);

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('platform_user_audit_%s_%s', $id, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: fn (int $page): array => $useCase->execute($targetUserId, array_merge($filters, ['page' => $page])),
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

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function toUserPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'name' => 'name',
            'email' => 'email',
            'roleIds' => 'role_ids',
        ];

        $payload = [];
        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        if (array_key_exists('approvalCaseReference', $validated)) {
            $payload['approval_case_reference'] = $validated['approvalCaseReference'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    private function toFacilityAssignmentPayload(array $validated): array
    {
        $assignments = $validated['facilityAssignments'] ?? [];
        if (! is_array($assignments)) {
            return [];
        }

        return array_map(static fn (array $assignment): array => [
            'facility_id' => $assignment['facilityId'] ?? null,
            'role' => $assignment['role'] ?? null,
            'is_primary' => (bool) ($assignment['isPrimary'] ?? false),
            'is_active' => array_key_exists('isActive', $assignment)
                ? (bool) $assignment['isActive']
                : true,
        ], $assignments);
    }
}
