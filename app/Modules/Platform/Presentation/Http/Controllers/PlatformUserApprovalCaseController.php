<?php

namespace App\Modules\Platform\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Application\UseCases\AddPlatformUserApprovalCaseCommentUseCase;
use App\Modules\Platform\Application\UseCases\CreatePlatformUserApprovalCaseUseCase;
use App\Modules\Platform\Application\UseCases\DecidePlatformUserApprovalCaseUseCase;
use App\Modules\Platform\Application\UseCases\GetPlatformUserApprovalCaseUseCase;
use App\Modules\Platform\Application\UseCases\ListPlatformUserApprovalCaseAuditLogsUseCase;
use App\Modules\Platform\Application\UseCases\ListPlatformUserApprovalCasesUseCase;
use App\Modules\Platform\Application\UseCases\UpdatePlatformUserApprovalCaseStatusUseCase;
use App\Modules\Platform\Presentation\Http\Requests\DecidePlatformUserApprovalCaseRequest;
use App\Modules\Platform\Presentation\Http\Requests\StorePlatformUserApprovalCaseCommentRequest;
use App\Modules\Platform\Presentation\Http\Requests\StorePlatformUserApprovalCaseRequest;
use App\Modules\Platform\Presentation\Http\Requests\UpdatePlatformUserApprovalCaseStatusRequest;
use App\Modules\Platform\Presentation\Http\Transformers\PlatformUserApprovalCaseAuditLogResponseTransformer;
use App\Modules\Platform\Presentation\Http\Transformers\PlatformUserApprovalCaseResponseTransformer;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformUserApprovalCaseController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListPlatformUserApprovalCasesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([PlatformUserApprovalCaseResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function store(
        StorePlatformUserApprovalCaseRequest $request,
        CreatePlatformUserApprovalCaseUseCase $useCase
    ): JsonResponse {
        try {
            $approvalCase = $useCase->execute(
                payload: $this->toApprovalCasePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DomainException $exception) {
            return $this->validationError($this->createValidationField($exception->getMessage()), $exception->getMessage());
        }

        return response()->json([
            'data' => PlatformUserApprovalCaseResponseTransformer::transform($approvalCase),
        ], 201);
    }

    public function show(string $id, GetPlatformUserApprovalCaseUseCase $useCase): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return $this->validationError('id', 'Approval case id must be a valid UUID.');
        }

        $approvalCase = $useCase->execute($id);
        abort_if($approvalCase === null, 404, 'Approval case not found.');

        return response()->json([
            'data' => PlatformUserApprovalCaseResponseTransformer::transform($approvalCase),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdatePlatformUserApprovalCaseStatusRequest $request,
        UpdatePlatformUserApprovalCaseStatusUseCase $useCase
    ): JsonResponse {
        if (! Str::isUuid($id)) {
            return $this->validationError('id', 'Approval case id must be a valid UUID.');
        }

        try {
            $approvalCase = $useCase->execute(
                id: $id,
                status: (string) $request->validated('status'),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DomainException $exception) {
            return $this->validationError('status', $exception->getMessage());
        }

        abort_if($approvalCase === null, 404, 'Approval case not found.');

        return response()->json([
            'data' => PlatformUserApprovalCaseResponseTransformer::transform($approvalCase),
        ]);
    }

    public function decide(
        string $id,
        DecidePlatformUserApprovalCaseRequest $request,
        DecidePlatformUserApprovalCaseUseCase $useCase
    ): JsonResponse {
        if (! Str::isUuid($id)) {
            return $this->validationError('id', 'Approval case id must be a valid UUID.');
        }

        try {
            $approvalCase = $useCase->execute(
                id: $id,
                decision: (string) $request->validated('decision'),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DomainException $exception) {
            $field = str_contains(strtolower($exception->getMessage()), 'reason')
                ? 'reason'
                : 'decision';

            return $this->validationError($field, $exception->getMessage());
        }

        abort_if($approvalCase === null, 404, 'Approval case not found.');

        return response()->json([
            'data' => PlatformUserApprovalCaseResponseTransformer::transform($approvalCase),
        ]);
    }

    public function comments(
        string $id,
        GetPlatformUserApprovalCaseUseCase $useCase
    ): JsonResponse {
        if (! Str::isUuid($id)) {
            return $this->validationError('id', 'Approval case id must be a valid UUID.');
        }

        $approvalCase = $useCase->execute($id);
        abort_if($approvalCase === null, 404, 'Approval case not found.');

        return response()->json([
            'data' => array_map(
                [PlatformUserApprovalCaseResponseTransformer::class, 'transformComment'],
                (array) ($approvalCase['comments'] ?? []),
            ),
        ]);
    }

    public function addComment(
        string $id,
        StorePlatformUserApprovalCaseCommentRequest $request,
        AddPlatformUserApprovalCaseCommentUseCase $useCase
    ): JsonResponse {
        if (! Str::isUuid($id)) {
            return $this->validationError('id', 'Approval case id must be a valid UUID.');
        }

        try {
            $comment = $useCase->execute(
                approvalCaseId: $id,
                commentText: (string) $request->validated('comment'),
                authorUserId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DomainException $exception) {
            return $this->validationError('comment', $exception->getMessage());
        }

        abort_if($comment === null, 404, 'Approval case not found.');

        return response()->json([
            'data' => PlatformUserApprovalCaseResponseTransformer::transformComment($comment),
        ], 201);
    }

    public function auditLogs(
        string $id,
        Request $request,
        ListPlatformUserApprovalCaseAuditLogsUseCase $useCase
    ): JsonResponse {
        if (! Str::isUuid($id)) {
            return $this->validationError('id', 'Approval case id must be a valid UUID.');
        }

        $result = $useCase->execute($id, $request->all());
        abort_if($result === null, 404, 'Approval case not found.');

        return response()->json([
            'data' => array_map([PlatformUserApprovalCaseAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListPlatformUserApprovalCaseAuditLogsUseCase $useCase
    ): JsonResponse|StreamedResponse {
        if (! Str::isUuid($id)) {
            return $this->validationError('id', 'Approval case id must be a valid UUID.');
        }

        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute($id, $filters);
        abort_if($firstPage === null, 404, 'Approval case not found.');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf(
                'platform_user_approval_case_audit_%s_%s',
                $this->safeExportIdentifier($id, 'approval-case'),
                now()->format('Ymd_His'),
            ),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute($id, $pageFilters);
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

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function toApprovalCasePayload(array $validated): array
    {
        $payload = [];

        if (array_key_exists('facilityId', $validated)) {
            $payload['facility_id'] = $validated['facilityId'];
        }

        if (array_key_exists('targetUserId', $validated)) {
            $payload['target_user_id'] = $validated['targetUserId'];
        }

        if (array_key_exists('requesterUserId', $validated)) {
            $payload['requester_user_id'] = $validated['requesterUserId'];
        }

        if (array_key_exists('reviewerUserId', $validated)) {
            $payload['reviewer_user_id'] = $validated['reviewerUserId'];
        }

        if (array_key_exists('caseReference', $validated)) {
            $payload['case_reference'] = $validated['caseReference'];
        }

        if (array_key_exists('actionType', $validated)) {
            $payload['action_type'] = $validated['actionType'];
        }

        if (array_key_exists('actionPayload', $validated)) {
            $payload['action_payload'] = $validated['actionPayload'];
        }

        if (array_key_exists('status', $validated)) {
            $payload['status'] = $validated['status'];
        }

        return $payload;
    }

    private function createValidationField(string $message): string
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'case reference')) {
            return 'caseReference';
        }

        if (str_contains($normalized, 'target user')) {
            return 'targetUserId';
        }

        if (str_contains($normalized, 'requester user')) {
            return 'requesterUserId';
        }

        if (str_contains($normalized, 'reviewer user')) {
            return 'reviewerUserId';
        }

        if (str_contains($normalized, 'facility')) {
            return 'facilityId';
        }

        if (str_contains($normalized, 'status')) {
            return 'status';
        }

        if (str_contains($normalized, 'action type')) {
            return 'actionType';
        }

        return 'payload';
    }
}
