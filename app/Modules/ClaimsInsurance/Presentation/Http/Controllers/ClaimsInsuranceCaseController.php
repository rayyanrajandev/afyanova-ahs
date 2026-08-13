<?php

namespace App\Modules\ClaimsInsurance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClaimsInsurance\Application\Exceptions\ClaimsInsuranceReconciliationException;
use App\Modules\ClaimsInsurance\Application\Exceptions\InvoiceNotEligibleForClaimsInsuranceCaseException;
use App\Modules\ClaimsInsurance\Application\UseCases\CreateClaimsInsuranceCaseUseCase;
use App\Modules\ClaimsInsurance\Application\UseCases\GetClaimsInsuranceCaseUseCase;
use App\Modules\ClaimsInsurance\Application\UseCases\ListClaimsInsuranceCaseAuditLogsUseCase;
use App\Modules\ClaimsInsurance\Application\UseCases\ListClaimsInsuranceCasesUseCase;
use App\Modules\ClaimsInsurance\Application\UseCases\ListClaimsInsuranceCaseStatusCountsUseCase;
use App\Modules\ClaimsInsurance\Application\UseCases\ReconcileClaimsInsuranceCaseSettlementUseCase;
use App\Modules\ClaimsInsurance\Application\UseCases\UpdateClaimsInsuranceCaseStatusUseCase;
use App\Modules\ClaimsInsurance\Application\UseCases\UpdateClaimsInsuranceCaseUseCase;
use App\Modules\ClaimsInsurance\Application\UseCases\UpdateClaimsInsuranceCaseReconciliationFollowUpUseCase;
use App\Modules\ClaimsInsurance\Presentation\Http\Requests\UpdateClaimsInsuranceCaseReconciliationFollowUpRequest;
use App\Modules\ClaimsInsurance\Presentation\Http\Requests\StoreClaimsInsuranceCaseRequest;
use App\Modules\ClaimsInsurance\Presentation\Http\Requests\UpdateClaimsInsuranceCaseReconciliationRequest;
use App\Modules\ClaimsInsurance\Presentation\Http\Requests\UpdateClaimsInsuranceCaseRequest;
use App\Modules\ClaimsInsurance\Presentation\Http\Requests\UpdateClaimsInsuranceCaseStatusRequest;
use App\Modules\ClaimsInsurance\Presentation\Http\Transformers\ClaimsInsuranceCaseAuditLogResponseTransformer;
use App\Modules\ClaimsInsurance\Presentation\Http\Transformers\ClaimsInsuranceCaseResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClaimsInsuranceCaseController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListClaimsInsuranceCasesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([ClaimsInsuranceCaseResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListClaimsInsuranceCaseStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function store(StoreClaimsInsuranceCaseRequest $request, CreateClaimsInsuranceCaseUseCase $useCase): JsonResponse
    {
        try {
            $case = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InvoiceNotEligibleForClaimsInsuranceCaseException $exception) {
            return $this->validationError('invoiceId', $exception->getMessage());
        }

        return response()->json([
            'data' => ClaimsInsuranceCaseResponseTransformer::transform($case),
        ], 201);
    }

    public function show(string $id, GetClaimsInsuranceCaseUseCase $useCase): JsonResponse
    {
        $case = $useCase->execute($id);
        abort_if($case === null, 404, 'Claims insurance case not found.');

        return response()->json([
            'data' => ClaimsInsuranceCaseResponseTransformer::transform($case),
        ]);
    }

    public function update(string $id, UpdateClaimsInsuranceCaseRequest $request, UpdateClaimsInsuranceCaseUseCase $useCase): JsonResponse
    {
        try {
            $case = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InvoiceNotEligibleForClaimsInsuranceCaseException $exception) {
            return $this->validationError('invoiceId', $exception->getMessage());
        }

        abort_if($case === null, 404, 'Claims insurance case not found.');

        return response()->json([
            'data' => ClaimsInsuranceCaseResponseTransformer::transform($case),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateClaimsInsuranceCaseStatusRequest $request,
        UpdateClaimsInsuranceCaseStatusUseCase $useCase
    ): JsonResponse {
        try {
            $case = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                decisionReason: $request->input('decisionReason'),
                submittedAt: $request->input('submittedAt'),
                adjudicatedAt: $request->input('adjudicatedAt'),
                approvedAmount: $request->has('approvedAmount') ? (float) $request->input('approvedAmount') : null,
                rejectedAmount: $request->has('rejectedAmount') ? (float) $request->input('rejectedAmount') : null,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($case === null, 404, 'Claims insurance case not found.');

        return response()->json([
            'data' => ClaimsInsuranceCaseResponseTransformer::transform($case),
        ]);
    }

    public function reconcile(
        string $id,
        UpdateClaimsInsuranceCaseReconciliationRequest $request,
        ReconcileClaimsInsuranceCaseSettlementUseCase $useCase
    ): JsonResponse {
        try {
            $case = $useCase->execute(
                id: $id,
                settledAmount: (float) $request->input('settledAmount'),
                settledAt: $request->input('settledAt'),
                settlementReference: $request->input('settlementReference'),
                reconciliationNotes: $request->input('reconciliationNotes'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (ClaimsInsuranceReconciliationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($case === null, 404, 'Claims insurance case not found.');

        return response()->json([
            'data' => ClaimsInsuranceCaseResponseTransformer::transform($case),
        ]);
    }

    public function updateReconciliationFollowUp(
        string $id,
        UpdateClaimsInsuranceCaseReconciliationFollowUpRequest $request,
        UpdateClaimsInsuranceCaseReconciliationFollowUpUseCase $useCase
    ): JsonResponse {
        try {
            $case = $useCase->execute(
                id: $id,
                followUpStatus: $request->string('followUpStatus')->value(),
                followUpDueAt: $request->input('followUpDueAt'),
                followUpNote: $request->input('followUpNote'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (ClaimsInsuranceReconciliationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($case === null, 404, 'Claims insurance case not found.');

        return response()->json([
            'data' => ClaimsInsuranceCaseResponseTransformer::transform($case),
        ]);
    }

    public function auditLogs(string $id, Request $request, ListClaimsInsuranceCaseAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(claimsInsuranceCaseId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Claims insurance case not found.');

        return response()->json([
            'data' => array_map([ClaimsInsuranceCaseAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListClaimsInsuranceCaseAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            claimsInsuranceCaseId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Claims insurance case not found.');

        $safeId = $this->safeExportIdentifier($id, 'claims-insurance-case');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('claims_insurance_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    claimsInsuranceCaseId: $id,
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
            'invoiceId' => 'invoice_id',
            'payerType' => 'payer_type',
            'payerName' => 'payer_name',
            'payerPlanName' => 'payer_plan_name',
            'payerReference' => 'payer_reference',
            'patientInsuranceRecordId' => 'patient_insurance_record_id',
            'memberId' => 'member_id',
            'policyNumber' => 'policy_number',
            'cardNumber' => 'card_number',
            'verificationReference' => 'verification_reference',
            'submittedAt' => 'submitted_at',
            'notes' => 'notes',
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
