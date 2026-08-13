<?php

namespace App\Modules\Staff\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Staff\Application\Exceptions\DuplicateStaffPrivilegeGrantException;
use App\Modules\Staff\Application\Exceptions\InvalidClinicalPrivilegeCatalogAssignmentException;
use App\Modules\Staff\Application\Exceptions\InvalidStaffPrivilegeGrantStatusTransitionException;
use App\Modules\Staff\Application\Exceptions\InvalidStaffPrivilegeGrantAssignmentException;
use App\Modules\Staff\Application\Exceptions\StaffPrivilegeGrantCredentialingNotReadyException;
use App\Modules\Staff\Application\Exceptions\UnverifiedStaffUserEmailException;
use App\Modules\Staff\Application\Exceptions\UnknownClinicalPrivilegeCatalogException;
use App\Modules\Staff\Application\Exceptions\UnknownClinicalSpecialtyException;
use App\Modules\Staff\Application\UseCases\CreateStaffPrivilegeGrantUseCase;
use App\Modules\Staff\Application\UseCases\GetStaffPrivilegeCoverageBoardUseCase;
use App\Modules\Staff\Application\UseCases\GetStaffPrivilegeGrantUseCase;
use App\Modules\Staff\Application\UseCases\ListStaffPrivilegeGrantAuditLogsUseCase;
use App\Modules\Staff\Application\UseCases\ListStaffPrivilegeGrantsUseCase;
use App\Modules\Staff\Application\UseCases\UpdateStaffPrivilegeGrantStatusUseCase;
use App\Modules\Staff\Application\UseCases\UpdateStaffPrivilegeGrantUseCase;
use App\Modules\Staff\Presentation\Http\Requests\StoreStaffPrivilegeGrantRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateStaffPrivilegeGrantRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateStaffPrivilegeGrantStatusRequest;
use App\Modules\Staff\Presentation\Http\Transformers\StaffCredentialingSummaryResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\StaffDocumentResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\StaffPrivilegeGrantAuditLogResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\StaffPrivilegeGrantResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffPrivilegeGrantController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function coverageBoard(Request $request, GetStaffPrivilegeCoverageBoardUseCase $useCase): JsonResponse
    {
        $includeDocuments = (bool) $request->user()?->can('staff.documents.read');
        $includeCredentialing = (bool) $request->user()?->can('staff.credentialing.read');
        $result = $useCase->execute(
            filters: $request->all(),
            includeDocuments: $includeDocuments,
            includeCredentialing: $includeCredentialing,
        );

        return response()->json([
            'data' => array_map(function (array $row): array {
                return [
                    'id' => $row['id'] ?? null,
                    'userId' => $row['user_id'] ?? null,
                    'userName' => $row['user_name'] ?? null,
                    'employeeNumber' => $row['employee_number'] ?? null,
                    'jobTitle' => $row['job_title'] ?? null,
                    'department' => $row['department'] ?? null,
                    'professionalLicenseNumber' => $row['professional_license_number'] ?? null,
                    'licenseType' => $row['license_type'] ?? null,
                    'phoneExtension' => $row['phone_extension'] ?? null,
                    'employmentType' => $row['employment_type'] ?? null,
                    'status' => $row['status'] ?? null,
                    'statusReason' => $row['status_reason'] ?? null,
                    'privileges' => array_map(
                        [StaffPrivilegeGrantResponseTransformer::class, 'transform'],
                        $row['privileges'] ?? [],
                    ),
                    'documents' => array_map(
                        [StaffDocumentResponseTransformer::class, 'transform'],
                        $row['documents'] ?? [],
                    ),
                    'credentialingSummary' => isset($row['credentialing_summary']) && is_array($row['credentialing_summary'])
                        ? StaffCredentialingSummaryResponseTransformer::transform($row['credentialing_summary'])
                        : null,
                ];
            }, $result['data'] ?? []),
            'meta' => $result['meta'] ?? [],
        ]);
    }

    public function index(string $id, Request $request, ListStaffPrivilegeGrantsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(staffProfileId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => array_map([StaffPrivilegeGrantResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function store(
        string $id,
        StoreStaffPrivilegeGrantRequest $request,
        CreateStaffPrivilegeGrantUseCase $useCase
    ): JsonResponse {
        try {
            $grant = $useCase->execute(
                staffProfileId: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateStaffPrivilegeGrantException $exception) {
            return $this->validationError('privilegeCode', $exception->getMessage());
        } catch (InvalidStaffPrivilegeGrantAssignmentException $exception) {
            return $this->validationError('facilityId', $exception->getMessage());
        } catch (InvalidClinicalPrivilegeCatalogAssignmentException $exception) {
            return $this->validationError('privilegeCatalogId', $exception->getMessage());
        } catch (StaffPrivilegeGrantCredentialingNotReadyException $exception) {
            return $this->validationError('staffProfileId', $exception->getMessage());
        } catch (UnverifiedStaffUserEmailException $exception) {
            return $this->validationError('linkedUser', $exception->getMessage());
        } catch (UnknownClinicalPrivilegeCatalogException $exception) {
            return $this->validationError('privilegeCatalogId', $exception->getMessage());
        } catch (UnknownClinicalSpecialtyException $exception) {
            return $this->validationError('specialtyId', $exception->getMessage());
        }

        abort_if($grant === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => StaffPrivilegeGrantResponseTransformer::transform($grant),
        ], 201);
    }

    public function show(string $id, string $privilegeId, GetStaffPrivilegeGrantUseCase $useCase): JsonResponse
    {
        $grant = $useCase->execute(
            staffProfileId: $id,
            staffPrivilegeGrantId: $privilegeId,
        );
        abort_if($grant === null, 404, 'Staff privilege grant not found.');

        return response()->json([
            'data' => StaffPrivilegeGrantResponseTransformer::transform($grant),
        ]);
    }

    public function update(
        string $id,
        string $privilegeId,
        UpdateStaffPrivilegeGrantRequest $request,
        UpdateStaffPrivilegeGrantUseCase $useCase
    ): JsonResponse {
        try {
            $grant = $useCase->execute(
                staffProfileId: $id,
                staffPrivilegeGrantId: $privilegeId,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateStaffPrivilegeGrantException $exception) {
            return $this->validationError('privilegeCode', $exception->getMessage());
        } catch (InvalidStaffPrivilegeGrantAssignmentException $exception) {
            return $this->validationError('facilityId', $exception->getMessage());
        } catch (InvalidClinicalPrivilegeCatalogAssignmentException $exception) {
            return $this->validationError('privilegeCatalogId', $exception->getMessage());
        } catch (UnverifiedStaffUserEmailException $exception) {
            return $this->validationError('linkedUser', $exception->getMessage());
        } catch (UnknownClinicalPrivilegeCatalogException $exception) {
            return $this->validationError('privilegeCatalogId', $exception->getMessage());
        } catch (UnknownClinicalSpecialtyException $exception) {
            return $this->validationError('specialtyId', $exception->getMessage());
        }

        abort_if($grant === null, 404, 'Staff privilege grant not found.');

        return response()->json([
            'data' => StaffPrivilegeGrantResponseTransformer::transform($grant),
        ]);
    }

    public function updateStatus(
        string $id,
        string $privilegeId,
        UpdateStaffPrivilegeGrantStatusRequest $request,
        UpdateStaffPrivilegeGrantStatusUseCase $useCase
    ): JsonResponse {
        try {
            $grant = $useCase->execute(
                staffProfileId: $id,
                staffPrivilegeGrantId: $privilegeId,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InvalidStaffPrivilegeGrantStatusTransitionException $exception) {
            return $this->validationError('status', $exception->getMessage());
        } catch (StaffPrivilegeGrantCredentialingNotReadyException $exception) {
            return $this->validationError('status', $exception->getMessage());
        } catch (UnverifiedStaffUserEmailException $exception) {
            return $this->validationError('linkedUser', $exception->getMessage());
        }

        abort_if($grant === null, 404, 'Staff privilege grant not found.');

        return response()->json([
            'data' => StaffPrivilegeGrantResponseTransformer::transform($grant),
        ]);
    }

    public function auditLogs(
        string $id,
        string $privilegeId,
        Request $request,
        ListStaffPrivilegeGrantAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(
            staffProfileId: $id,
            staffPrivilegeGrantId: $privilegeId,
            filters: $request->all(),
        );
        abort_if($result === null, 404, 'Staff privilege grant not found.');

        return response()->json([
            'data' => array_map([StaffPrivilegeGrantAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        string $privilegeId,
        Request $request,
        ListStaffPrivilegeGrantAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            staffProfileId: $id,
            staffPrivilegeGrantId: $privilegeId,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Staff privilege grant not found.');

        $safeStaffId = $this->safeExportIdentifier($id, 'staff-profile');
        $safePrivilegeId = $this->safeExportIdentifier($privilegeId, 'staff-privilege');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf(
                'staff_privilege_audit_%s_%s_%s',
                $safeStaffId,
                $safePrivilegeId,
                now()->format('Ymd_His'),
            ),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $privilegeId, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    staffProfileId: $id,
                    staffPrivilegeGrantId: $privilegeId,
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
            'facilityId' => 'facility_id',
            'privilegeCatalogId' => 'privilege_catalog_id',
            'specialtyId' => 'specialty_id',
            'privilegeCode' => 'privilege_code',
            'privilegeName' => 'privilege_name',
            'scopeNotes' => 'scope_notes',
            'grantedAt' => 'granted_at',
            'reviewDueAt' => 'review_due_at',
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
