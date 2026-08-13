<?php

namespace App\Modules\Staff\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Staff\Application\Exceptions\DuplicateStaffProfileForUserException;
use App\Modules\Staff\Application\Exceptions\UserNotEligibleForStaffProfileException;
use App\Modules\Staff\Application\UseCases\CreateStaffProfileUseCase;
use App\Modules\Staff\Application\UseCases\GetEligibleStaffUserUseCase;
use App\Modules\Staff\Application\UseCases\SearchEligibleStaffUsersUseCase;
use App\Modules\Staff\Application\UseCases\GetStaffProfileUseCase;
use App\Modules\Staff\Application\UseCases\ListStaffDepartmentOptionsUseCase;
use App\Modules\Staff\Application\UseCases\LocateStaffProfileQueuePageUseCase;
use App\Modules\Staff\Application\UseCases\ListStaffProfileAuditLogsUseCase;
use App\Modules\Staff\Application\UseCases\ListStaffProfileStatusCountsUseCase;
use App\Modules\Staff\Application\UseCases\ListStaffProfilesUseCase;
use App\Modules\Staff\Application\UseCases\UpdateStaffProfileStatusUseCase;
use App\Modules\Staff\Application\UseCases\UpdateStaffProfileUseCase;
use App\Modules\Staff\Presentation\Http\Requests\StoreStaffProfileRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateStaffProfileRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateStaffProfileStatusRequest;
use App\Modules\Staff\Presentation\Http\Transformers\StaffEligibleUserResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\StaffProfileAuditLogResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\StaffProfileResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffProfileController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListStaffProfilesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([StaffProfileResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function clinicalDirectory(Request $request, ListStaffProfilesUseCase $useCase): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'status' => 'active',
            'clinicalOnly' => true,
            'page' => max(1, (int) $request->integer('page', 1)),
            'perPage' => min(max((int) $request->integer('perPage', 200), 1), 200),
        ]);

        $result = $useCase->execute($filters);

        return response()->json([
            'data' => array_map([StaffProfileResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function departmentOptions(ListStaffDepartmentOptionsUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase->execute(),
        ]);
    }

    public function statusCounts(Request $request, ListStaffProfileStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function eligibleUsers(Request $request, SearchEligibleStaffUsersUseCase $useCase): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $perPage = max(1, min((int) $request->integer('perPage', 10), 20));
        $users = $useCase->execute($query, $perPage);

        return response()->json([
            'data' => array_map([StaffEligibleUserResponseTransformer::class, 'transform'], $users),
        ]);
    }

    public function showEligibleUser(string $userId, GetEligibleStaffUserUseCase $useCase): JsonResponse
    {
        abort_if(! ctype_digit($userId), 404, 'Linked user not found.');

        $user = $useCase->execute((int) $userId);
        abort_if($user === null, 404, 'Linked user not found.');

        return response()->json([
            'data' => StaffEligibleUserResponseTransformer::transform($user),
        ]);
    }

    public function store(StoreStaffProfileRequest $request, CreateStaffProfileUseCase $useCase): JsonResponse
    {
        try {
            $profile = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (UserNotEligibleForStaffProfileException|DuplicateStaffProfileForUserException $exception) {
            return $this->validationError('userId', $exception->getMessage());
        }

        return response()->json([
            'data' => StaffProfileResponseTransformer::transform($profile),
        ], 201);
    }

    public function show(string $id, GetStaffProfileUseCase $useCase): JsonResponse
    {
        $profile = $useCase->execute($id);
        abort_if($profile === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => StaffProfileResponseTransformer::transform($profile),
        ]);
    }

    public function queuePosition(
        string $id,
        Request $request,
        LocateStaffProfileQueuePageUseCase $useCase
    ): JsonResponse {
        $position = $useCase->execute($id, $request->all());
        abort_if($position === null, 404, 'Staff profile is not visible in the current queue filters.');

        return response()->json([
            'data' => $position,
        ]);
    }

    public function update(string $id, UpdateStaffProfileRequest $request, UpdateStaffProfileUseCase $useCase): JsonResponse
    {
        try {
            $profile = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (UserNotEligibleForStaffProfileException|DuplicateStaffProfileForUserException $exception) {
            return $this->validationError('userId', $exception->getMessage());
        }

        abort_if($profile === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => StaffProfileResponseTransformer::transform($profile),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateStaffProfileStatusRequest $request,
        UpdateStaffProfileStatusUseCase $useCase
    ): JsonResponse {
        try {
            $profile = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($profile === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => StaffProfileResponseTransformer::transform($profile),
        ]);
    }

    public function auditLogs(string $id, Request $request, ListStaffProfileAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(staffProfileId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => array_map([StaffProfileAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListStaffProfileAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            staffProfileId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Staff profile not found.');

        $safeId = $this->safeExportIdentifier($id, 'staff-profile');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('staff_profile_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    staffProfileId: $id,
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

    private function toPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'userId' => 'user_id',
            'department' => 'department',
            'departmentId' => 'department_id',
            'jobTitle' => 'job_title',
            'professionalLicenseNumber' => 'professional_license_number',
            'licenseType' => 'license_type',
            'phoneExtension' => 'phone_extension',
            'employmentType' => 'employment_type',
        ];

        $payload = [];
        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }
            $payload[$storageKey] = $validated[$requestKey];
        }

        // When departmentId is provided, look up department name and set both fields
        if (array_key_exists('departmentId', $validated) && ! empty($validated['departmentId'])) {
            $departmentId = $validated['departmentId'];
            $payload['department_id'] = $departmentId;
            
            // Look up department name from ID
            $department = \Illuminate\Support\Facades\DB::table('departments')
                ->where('id', $departmentId)
                ->select('name')
                ->first();
            
            if ($department && ! empty($department->name)) {
                $payload['department'] = $department->name;
            } else {
                // Fallback: If department lookup fails, use the ID as the department name
                // This ensures the NOT NULL constraint is satisfied while flagging the issue
                $payload['department'] = $departmentId;
            }
        }

        return $payload;
    }
}

