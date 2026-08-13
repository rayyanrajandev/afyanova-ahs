<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Jobs\GenerateAuditExportCsvJob;
use App\Jobs\GenerateBillingReportExportJob;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\Exceptions\AdmissionNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Exceptions\AppointmentNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Exceptions\BillingInvoiceDraftOnlyFieldUpdateNotAllowedException;
use App\Modules\Billing\Application\Exceptions\BillingInvoiceLineItemsUpdateNotAllowedException;
use App\Modules\Billing\Application\Exceptions\BillingInvoicePaymentRecordingNotAllowedException;
use App\Modules\Billing\Application\Exceptions\BillingInvoicePaymentReversalNotAllowedException;
use App\Modules\Billing\Application\Exceptions\EncounterNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Exceptions\BillingInvoicePricingResolutionException;
use App\Modules\Billing\Application\Exceptions\PatientNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Support\BillingFinancePostingSnapshotService;
use App\Modules\Billing\Application\UseCases\AddInvoiceAdjustmentUseCase;
use App\Modules\Billing\Application\UseCases\CreateBillingInvoiceUseCase;
use App\Modules\Billing\Application\UseCases\GetBillingAgingReportUseCase;
use App\Modules\Billing\Application\UseCases\GetBillingFinancialControlSummaryUseCase;
use App\Modules\Billing\Application\UseCases\GetBillingInvoiceFinancePostingSummaryUseCase;
use App\Modules\Billing\Application\UseCases\GetBillingInvoiceUseCase;
use App\Modules\Billing\Application\UseCases\GetBillingRevenueRecognitionSummaryUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingChargeCaptureCandidatesUseCase;
use App\Modules\Billing\Application\UseCases\ListCashierQueueUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingInvoiceAuditLogsUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingInvoicesUseCase;
use App\Modules\Billing\Application\UseCases\ListInvoiceAdjustmentsUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingInvoiceStatusCountsUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingInvoicePaymentsUseCase;
use App\Modules\Billing\Application\UseCases\PreviewBillingInvoiceUseCase;
use App\Modules\Billing\Application\UseCases\RecordBillingInvoicePaymentUseCase;
use App\Modules\Billing\Application\UseCases\ReverseBillingInvoicePaymentUseCase;
use App\Modules\Billing\Application\UseCases\UpdateBillingInvoiceStatusUseCase;
use App\Modules\Billing\Application\UseCases\UpdateBillingInvoiceUseCase;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingReportExportJobModel;
use App\Modules\Billing\Presentation\Http\Requests\AddInvoiceAdjustmentRequest;
use App\Modules\Billing\Presentation\Http\Requests\RecordBillingInvoicePaymentRequest;
use App\Modules\Billing\Presentation\Http\Requests\ReverseBillingInvoicePaymentRequest;
use App\Modules\Billing\Presentation\Http\Requests\StoreBillingInvoiceRequest;
use App\Modules\Billing\Presentation\Http\Requests\UpdateBillingInvoiceRequest;
use App\Modules\Billing\Presentation\Http\Requests\UpdateBillingInvoiceStatusRequest;
use App\Modules\Billing\Presentation\Http\Transformers\BillingAgingReportResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingInvoiceAdjustmentResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingInvoiceAuditLogResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingInvoicePaymentResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingInvoiceResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Infrastructure\Models\AuditExportJobModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingInvoiceController extends Controller
{
    // API: billing-invoices.* (cashier-queue, status-counts, charge-capture-candidates, financial-controls, etc.)
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    private const FINANCIAL_CONTROLS_CSV_SCHEMA_VERSION = 'billing-financial-controls-csv.v1';

    private const FINANCIAL_CONTROLS_CSV_COLUMNS = ['path', 'value'];

    private const AUDIT_EXPORT_MODULE = GenerateAuditExportCsvJob::MODULE_BILLING;

    private const AGING_REPORT_CSV_SCHEMA_VERSION = 'billing-aging-report-csv.v1';

    public function __construct(
        private readonly BillingFinancePostingSnapshotService $financePostingSnapshotService,
    ) {}

    public function index(Request $request, ListBillingInvoicesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        $financeSummaries = $this->financePostingSnapshotService->invoiceSummaries(
            array_values(
                array_filter(
                    array_map(
                        static fn (array $invoice): ?string => $invoice['id'] ?? null,
                        $result['data'],
                    ),
                ),
            ),
        );

        return response()->json([
            'data' => array_map(
                fn (array $invoice): array => $this->transformInvoice($invoice, $financeSummaries),
                $result['data'],
            ),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListBillingInvoiceStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function cashierQueue(Request $request, ListCashierQueueUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json($result);
    }

    public function financialControlsSummary(Request $request, GetBillingFinancialControlSummaryUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase->execute($request->all()),
        ]);
    }

    public function revenueRecognitionSummary(
        Request $request,
        GetBillingRevenueRecognitionSummaryUseCase $useCase
    ): JsonResponse {
        return response()->json([
            'data' => $useCase->execute($request->all()),
        ]);
    }

    public function financialControlsDepartmentOptions(BillingInvoiceRepositoryInterface $billingInvoiceRepository): JsonResponse
    {
        return response()->json([
            'data' => $billingInvoiceRepository->billingDepartmentOptions(),
        ]);
    }

    public function chargeCaptureCandidates(
        Request $request,
        ListBillingChargeCaptureCandidatesUseCase $useCase
    ): JsonResponse {
        $includeInvoiced = $request->query('includeInvoiced');
        if (is_string($includeInvoiced)) {
            $normalizedIncludeInvoiced = strtolower(trim($includeInvoiced));

            if ($normalizedIncludeInvoiced === 'true') {
                $request->merge(['includeInvoiced' => true]);
            } elseif ($normalizedIncludeInvoiced === 'false') {
                $request->merge(['includeInvoiced' => false]);
            }
        }

        $validated = $request->validate([
            'patientId' => ['required', 'uuid'],
            'encounterId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'currencyCode' => ['nullable', 'string', 'size:3'],
            'includeInvoiced' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($useCase->execute($validated));
    }

    public function exportFinancialControlsSummaryCsv(
        Request $request,
        GetBillingFinancialControlSummaryUseCase $useCase
    ): StreamedResponse {
        $summary = $useCase->execute($request->all());

        return $this->streamCsvExport(
            baseName: sprintf('billing_financial_controls_summary_%s', now()->format('Ymd_His')),
            columns: self::FINANCIAL_CONTROLS_CSV_COLUMNS,
            writeRows: function ($output) use ($summary): void {
                $rows = [];
                $this->flattenFinancialControlsSummary($summary, '', $rows);
                usort($rows, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));

                foreach ($rows as $row) {
                    fputcsv($output, [$row[0], $row[1]]);
                }
            },
            schemaHeaderName: 'X-Billing-Financial-Controls-CSV-Schema-Version',
            schemaVersion: self::FINANCIAL_CONTROLS_CSV_SCHEMA_VERSION,
        );
    }

    public function store(StoreBillingInvoiceRequest $request, CreateBillingInvoiceUseCase $useCase): JsonResponse
    {
        try {
            $result = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForBillingInvoiceException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForBillingInvoiceException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForBillingInvoiceException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (EncounterNotEligibleForBillingInvoiceException $exception) {
            return $this->validationError('encounterId', $exception->getMessage());
        } catch (BillingInvoicePricingResolutionException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        return response()->json([
            'data' => BillingInvoiceResponseTransformer::transform($result['invoice']),
            'meta' => [
                'draftReused' => (bool) ($result['draft_reused'] ?? false),
            ],
        ], (bool) ($result['draft_reused'] ?? false) ? 200 : 201);
    }

    public function preview(StoreBillingInvoiceRequest $request, PreviewBillingInvoiceUseCase $useCase): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user !== null
            && ($user->can('billing.invoices.create') || $user->can('billing.invoices.update-draft')),
            403,
        );

        try {
            $invoice = $useCase->execute($this->toPersistencePayload($request->validated()));
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForBillingInvoiceException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForBillingInvoiceException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForBillingInvoiceException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (EncounterNotEligibleForBillingInvoiceException $exception) {
            return $this->validationError('encounterId', $exception->getMessage());
        } catch (BillingInvoicePricingResolutionException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        return response()->json([
            'data' => BillingInvoiceResponseTransformer::transform($invoice),
        ]);
    }

    public function show(string $id, GetBillingInvoiceUseCase $useCase): JsonResponse
    {
        $invoice = $useCase->execute($id);
        abort_if($invoice === null, 404, 'Billing invoice not found.');

        return response()->json([
            'data' => $this->transformInvoice($invoice),
        ]);
    }

    public function financePostingSummary(
        string $id,
        GetBillingInvoiceFinancePostingSummaryUseCase $useCase
    ): JsonResponse {
        $summary = $useCase->execute($id);
        abort_if($summary === null, 404, 'Billing invoice not found.');

        return response()->json([
            'data' => $summary,
        ]);
    }

    public function update(string $id, UpdateBillingInvoiceRequest $request, UpdateBillingInvoiceUseCase $useCase): JsonResponse
    {
        try {
            $invoice = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForBillingInvoiceException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForBillingInvoiceException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForBillingInvoiceException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (BillingInvoiceDraftOnlyFieldUpdateNotAllowedException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        } catch (BillingInvoiceLineItemsUpdateNotAllowedException $exception) {
            return $this->validationError('lineItems', $exception->getMessage());
        } catch (BillingInvoicePricingResolutionException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($invoice === null, 404, 'Billing invoice not found.');

        return response()->json([
            'data' => $this->transformInvoice($invoice),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateBillingInvoiceStatusRequest $request,
        UpdateBillingInvoiceStatusUseCase $useCase
    ): JsonResponse {
        try {
            $invoice = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                paidAmount: $request->filled('paidAmount') ? (float) $request->input('paidAmount') : null,
                paymentPayerType: $request->input('paymentPayerType'),
                paymentMethod: $request->input('paymentMethod'),
                paymentReference: $request->input('paymentReference'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($invoice === null, 404, 'Billing invoice not found.');

        return response()->json([
            'data' => $this->transformInvoice($invoice),
        ]);
    }

    public function auditLogs(string $id, Request $request, ListBillingInvoiceAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(billingInvoiceId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Billing invoice not found.');

        return response()->json([
            'data' => array_map([BillingInvoiceAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(string $id, Request $request, ListBillingInvoiceAuditLogsUseCase $useCase): StreamedResponse
    {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(billingInvoiceId: $id, filters: $filters);
        abort_if($firstPage === null, 404, 'Billing invoice not found.');

        $safeId = $this->safeExportIdentifier($id, 'billing-invoice');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('billing_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    billingInvoiceId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }

    public function createAuditLogsCsvExportJob(
        string $id,
        Request $request,
        ListBillingInvoiceAuditLogsUseCase $useCase
    ): JsonResponse {
        $filters = $this->normalizeAuditExportFilters($request);
        $resourceCheck = $useCase->execute(
            billingInvoiceId: $id,
            filters: array_merge($filters, ['page' => 1, 'perPage' => 1]),
        );
        abort_if($resourceCheck === null, 404, 'Billing invoice not found.');

        $auditExportJob = AuditExportJobModel::query()->create([
            'module' => self::AUDIT_EXPORT_MODULE,
            'target_resource_id' => $id,
            'status' => 'queued',
            'filters' => $filters,
            'created_by_user_id' => $request->user()?->id,
        ]);

        GenerateAuditExportCsvJob::dispatch((string) $auditExportJob->id);
        $auditExportJob->refresh();

        return response()->json([
            'data' => $this->transformAuditExportJob($auditExportJob, $id),
        ], 202);
    }

    public function auditLogsCsvExportJobs(
        string $id,
        Request $request,
        ListBillingInvoiceAuditLogsUseCase $useCase
    ): JsonResponse {
        $resourceCheck = $useCase->execute(
            billingInvoiceId: $id,
            filters: ['page' => 1, 'perPage' => 1],
        );
        abort_if($resourceCheck === null, 404, 'Billing invoice not found.');

        $perPage = max(min((int) $request->input('perPage', 10), 50), 1);
        $page = max((int) $request->input('page', 1), 1);
        $statusGroup = strtolower((string) $request->input('statusGroup', 'all'));
        if (! in_array($statusGroup, ['all', 'failed', 'backlog', 'completed'], true)) {
            $statusGroup = 'all';
        }

        $query = AuditExportJobModel::query()
            ->where('module', self::AUDIT_EXPORT_MODULE)
            ->where('target_resource_id', $id)
            ->where('created_by_user_id', $request->user()?->id)
            ->orderByDesc('created_at');

        if ($statusGroup === 'failed') {
            $query->where('status', 'failed');
        } elseif ($statusGroup === 'backlog') {
            $query->whereIn('status', ['queued', 'processing']);
        } elseif ($statusGroup === 'completed') {
            $query->where('status', 'completed');
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (AuditExportJobModel $job): array => $this->transformAuditExportJob($job, $id))
                ->values()
                ->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'filters' => [
                    'statusGroup' => $statusGroup,
                ],
            ],
        ]);
    }

    public function auditLogsCsvExportJob(string $id, string $jobId, Request $request): JsonResponse
    {
        $auditExportJob = $this->findAuditExportJob($id, $jobId, $request->user()?->id);
        abort_if($auditExportJob === null, 404, 'Audit export job not found.');

        return response()->json([
            'data' => $this->transformAuditExportJob($auditExportJob, $id),
        ]);
    }

    public function retryAuditLogsCsvExportJob(
        string $id,
        string $jobId,
        Request $request
    ): JsonResponse {
        $auditExportJob = $this->findAuditExportJob($id, $jobId, $request->user()?->id);
        abort_if($auditExportJob === null, 404, 'Audit export job not found.');

        $retryJob = AuditExportJobModel::query()->create([
            'module' => self::AUDIT_EXPORT_MODULE,
            'target_resource_id' => $id,
            'status' => 'queued',
            'filters' => is_array($auditExportJob->filters) ? $auditExportJob->filters : [],
            'created_by_user_id' => $request->user()?->id,
        ]);

        GenerateAuditExportCsvJob::dispatch((string) $retryJob->id);
        $retryJob->refresh();

        return response()->json([
            'data' => $this->transformAuditExportJob($retryJob, $id),
        ], 202);
    }

    public function downloadAuditLogsCsvExportJob(
        string $id,
        string $jobId,
        Request $request
    ): JsonResponse|StreamedResponse {
        $auditExportJob = $this->findAuditExportJob($id, $jobId, $request->user()?->id);
        abort_if($auditExportJob === null, 404, 'Audit export job not found.');

        if ($auditExportJob->status !== 'completed' || ! $auditExportJob->file_path) {
            return response()->json([
                'code' => 'EXPORT_JOB_NOT_READY',
                'message' => 'Audit export job is not ready for download.',
            ], 409);
        }

        $disk = Storage::disk('local');
        abort_if(! $disk->exists($auditExportJob->file_path), 404, 'Audit export file not found.');

        return $this->downloadStoredCsvExport(
            filePath: $auditExportJob->file_path,
            downloadName: $auditExportJob->file_name ?: $this->brandedCsvFilename(
                sprintf('billing_audit_%s', $this->safeExportIdentifier($id, 'billing-invoice'))
            ),
            schemaHeaderName: 'X-Audit-CSV-Schema-Version',
            schemaVersion: self::AUDIT_CSV_SCHEMA_VERSION,
        );
    }

    public function payments(string $id, Request $request, ListBillingInvoicePaymentsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(billingInvoiceId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Billing invoice not found.');

        return response()->json([
            'data' => array_map([BillingInvoicePaymentResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function recordPayment(
        string $id,
        RecordBillingInvoicePaymentRequest $request,
        RecordBillingInvoicePaymentUseCase $useCase
    ): JsonResponse {
        try {
            $result = $useCase->execute(
                billingInvoiceId: $id,
                amount: (float) $request->input('amount'),
                payerType: $request->string('payerType')->value(),
                paymentMethod: $request->string('paymentMethod')->value(),
                paymentReference: $request->input('paymentReference'),
                note: $request->input('note'),
                paymentAt: $request->input('paymentAt'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (BillingInvoicePaymentRecordingNotAllowedException $exception) {
            return $this->validationError('amount', $exception->getMessage());
        }

        abort_if($result === null, 404, 'Billing invoice not found.');

        return response()->json([
            'data' => [
                'invoice' => BillingInvoiceResponseTransformer::transform($result['invoice']),
                'payment' => BillingInvoicePaymentResponseTransformer::transform($result['payment']),
            ],
        ], 201);
    }

    public function addAdjustment(string $id, AddInvoiceAdjustmentRequest $request, AddInvoiceAdjustmentUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(
            invoiceId: $id,
            type: $request->string('type')->value(),
            amount: (float) $request->input('amount'),
            reason: $request->string('reason')->value(),
            actorId: $request->user()?->id,
        );

        abort_if($result === null, 404, 'Billing invoice not found.');

        return response()->json([
            'data' => [
                'invoice' => BillingInvoiceResponseTransformer::transform($result['invoice']),
                'adjustment' => BillingInvoiceAdjustmentResponseTransformer::transform($result['adjustment']),
            ],
        ], 201);
    }

    public function listAdjustments(string $id, ListInvoiceAdjustmentsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($id);

        abort_if($result === null, 404, 'Billing invoice not found.');

        return response()->json([
            'data' => array_map([BillingInvoiceAdjustmentResponseTransformer::class, 'transform'], $result['data']),
        ]);
    }

    public function agingReport(Request $request, GetBillingAgingReportUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'currencyCode' => ['nullable', 'string', 'size:3'],
            'asOfDate' => ['nullable', 'date'],
            'departmentFilter' => ['nullable', 'string'],
        ]);

        $report = $useCase->execute(
            currencyCode: $validated['currencyCode'] ?? null,
            asOfDate: $validated['asOfDate'] ?? null,
            departmentFilter: $validated['departmentFilter'] ?? null,
        );

        return response()->json([
            'data' => BillingAgingReportResponseTransformer::transform($report),
        ]);
    }

    /**
     * The aging report's own ->get() over every open invoice is fine for the
     * on-screen view above, but a full CSV export of it should not run
     * in-request — see GenerateBillingReportExportJob. Follows the same
     * queued->processing->completed/failed lifecycle as the audit-log
     * export jobs above.
     */
    public function agingReportExportJobs(
        Request $request,
        CurrentPlatformScopeContextInterface $scopeContext,
    ): JsonResponse {
        $validated = $request->validate([
            'currencyCode' => ['nullable', 'string', 'size:3'],
            'asOfDate' => ['nullable', 'date'],
            'departmentFilter' => ['nullable', 'string'],
        ]);

        $reportExportJob = BillingReportExportJobModel::query()->create([
            'tenant_id' => $scopeContext->tenantId(),
            'facility_id' => $scopeContext->facilityId(),
            'report_type' => 'aging',
            'filters' => $validated,
            'status' => 'queued',
            'created_by_user_id' => $request->user()?->id,
        ]);

        GenerateBillingReportExportJob::dispatch((string) $reportExportJob->id);
        $reportExportJob->refresh();

        return response()->json([
            'data' => $this->transformReportExportJob($reportExportJob),
        ], 202);
    }

    public function agingReportExportJob(string $jobId, Request $request): JsonResponse
    {
        $reportExportJob = $this->findReportExportJob($jobId, $request->user()?->id);
        abort_if($reportExportJob === null, 404, 'Report export job not found.');

        return response()->json([
            'data' => $this->transformReportExportJob($reportExportJob),
        ]);
    }

    public function downloadAgingReportExportJob(string $jobId, Request $request): JsonResponse|StreamedResponse
    {
        $reportExportJob = $this->findReportExportJob($jobId, $request->user()?->id);
        abort_if($reportExportJob === null, 404, 'Report export job not found.');

        if ($reportExportJob->status !== 'completed' || ! $reportExportJob->file_path) {
            return response()->json([
                'code' => 'EXPORT_JOB_NOT_READY',
                'message' => 'Report export job is not ready for download.',
            ], 409);
        }

        $disk = Storage::disk('local');
        abort_if(! $disk->exists($reportExportJob->file_path), 404, 'Report export file not found.');

        return $this->downloadStoredCsvExport(
            filePath: $reportExportJob->file_path,
            downloadName: $reportExportJob->file_name ?: $this->brandedCsvFilename('billing_aging_report'),
            schemaHeaderName: 'X-Billing-Report-CSV-Schema-Version',
            schemaVersion: self::AGING_REPORT_CSV_SCHEMA_VERSION,
        );
    }

    private function findReportExportJob(string $jobId, ?int $actorId): ?BillingReportExportJobModel
    {
        return BillingReportExportJobModel::query()
            ->where('id', $jobId)
            ->where('report_type', 'aging')
            ->where('created_by_user_id', $actorId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformReportExportJob(BillingReportExportJobModel $reportExportJob): array
    {
        $downloadUrl = null;
        if ($reportExportJob->status === 'completed' && $reportExportJob->file_path) {
            $downloadUrl = sprintf(
                '/api/v1/aging-report/export-jobs/%s/download',
                $reportExportJob->id,
            );
        }

        return [
            'id' => $reportExportJob->id,
            'status' => $reportExportJob->status,
            'rowCount' => $reportExportJob->row_count,
            'errorMessage' => $reportExportJob->error_message,
            'createdAt' => optional($reportExportJob->created_at)?->toISOString(),
            'startedAt' => optional($reportExportJob->started_at)?->toISOString(),
            'completedAt' => optional($reportExportJob->completed_at)?->toISOString(),
            'failedAt' => optional($reportExportJob->failed_at)?->toISOString(),
            'downloadUrl' => $downloadUrl,
        ];
    }

    public function reversePayment(
        string $id,
        string $paymentId,
        ReverseBillingInvoicePaymentRequest $request,
        ReverseBillingInvoicePaymentUseCase $useCase
    ): JsonResponse {
        try {
            $result = $useCase->execute(
                billingInvoiceId: $id,
                paymentId: $paymentId,
                amount: (float) $request->input('amount'),
                reason: $request->string('reason')->value(),
                approvalCaseReference: $request->input('approvalCaseReference'),
                note: $request->input('note'),
                reversalAt: $request->input('reversalAt'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (BillingInvoicePaymentReversalNotAllowedException $exception) {
            $message = $exception->getMessage();
            $field = str_contains(strtolower($message), 'approval case reference')
                ? 'approvalCaseReference'
                : 'amount';

            return $this->validationError($field, $message);
        }

        abort_if($result === null, 404, 'Billing invoice or payment entry not found.');

        return response()->json([
            'data' => [
                'invoice' => BillingInvoiceResponseTransformer::transform($result['invoice']),
                'reversal' => BillingInvoicePaymentResponseTransformer::transform($result['reversal']),
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAuditExportFilters(Request $request): array
    {
        $query = trim((string) $request->input('q', ''));
        $action = trim((string) $request->input('action', ''));
        $actorTypeInput = strtolower(trim((string) $request->input('actorType', '')));
        $actorType = in_array($actorTypeInput, ['system', 'user'], true) ? $actorTypeInput : null;
        $actorIdInput = trim((string) $request->input('actorId', ''));
        $actorId = $actorIdInput !== '' && ctype_digit($actorIdInput)
            ? $actorIdInput
            : null;
        $from = trim((string) $request->input('from', ''));
        $to = trim((string) $request->input('to', ''));

        return [
            'q' => $query !== '' ? $query : null,
            'action' => $action !== '' ? $action : null,
            'actorType' => $actorType,
            'actorId' => $actorId,
            'from' => $from !== '' ? $from : null,
            'to' => $to !== '' ? $to : null,
        ];
    }

    private function findAuditExportJob(string $resourceId, string $jobId, ?int $actorId): ?AuditExportJobModel
    {
        return AuditExportJobModel::query()
            ->where('id', $jobId)
            ->where('module', self::AUDIT_EXPORT_MODULE)
            ->where('target_resource_id', $resourceId)
            ->where('created_by_user_id', $actorId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAuditExportJob(AuditExportJobModel $auditExportJob, string $resourceId): array
    {
        $downloadUrl = null;
        if ($auditExportJob->status === 'completed' && $auditExportJob->file_path) {
            $downloadUrl = sprintf(
                '/api/v1/billing/%s/audit-logs/export-jobs/%s/download',
                $resourceId,
                $auditExportJob->id,
            );
        }

        return [
            'id' => $auditExportJob->id,
            'status' => $auditExportJob->status,
            'rowCount' => $auditExportJob->row_count,
            'schemaVersion' => self::AUDIT_CSV_SCHEMA_VERSION,
            'errorMessage' => $auditExportJob->error_message,
            'createdAt' => optional($auditExportJob->created_at)?->toISOString(),
            'startedAt' => optional($auditExportJob->started_at)?->toISOString(),
            'completedAt' => optional($auditExportJob->completed_at)?->toISOString(),
            'failedAt' => optional($auditExportJob->failed_at)?->toISOString(),
            'downloadUrl' => $downloadUrl,
        ];
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, array<string, mixed>>|null $financeSummaries
     * @return array<string, mixed>
     */
    private function transformInvoice(array $invoice, ?array $financeSummaries = null): array
    {
        $transformed = BillingInvoiceResponseTransformer::transform($invoice);
        $invoiceId = (string) ($invoice['id'] ?? '');

        if ($invoiceId === '') {
            return $transformed;
        }

        $transformed['financePosting'] = $financeSummaries[$invoiceId]
            ?? $this->financePostingSnapshotService->invoiceSummary($invoiceId);

        return $transformed;
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
            'patientId' => 'patient_id',
            'encounterId' => 'encounter_id',
            'admissionId' => 'admission_id',
            'appointmentId' => 'appointment_id',
            'billingPayerContractId' => 'billing_payer_contract_id',
            'issuedByUserId' => 'issued_by_user_id',
            'invoiceDate' => 'invoice_date',
            'currencyCode' => 'currency_code',
            'autoPriceLineItems' => 'auto_price_line_items',
            'subtotalAmount' => 'subtotal_amount',
            'discountAmount' => 'discount_amount',
            'taxAmount' => 'tax_amount',
            'paidAmount' => 'paid_amount',
            'paymentDueAt' => 'payment_due_at',
            'notes' => 'notes',
            'lineItems' => 'line_items',
        ];

        $payload = [];

        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        if (array_key_exists('currency_code', $payload) && is_string($payload['currency_code'])) {
            $payload['currency_code'] = strtoupper($payload['currency_code']);
        }

        if (array_key_exists('line_items', $payload)) {
            $payload['line_items'] = $this->normalizeLineItems($payload['line_items']);
        }

        return $payload;
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rows
     */
    private function flattenFinancialControlsSummary(mixed $value, string $path, array &$rows): void
    {
        if (is_array($value)) {
            if ($value === []) {
                $rows[] = [$path === '' ? 'root' : $path, '[]'];

                return;
            }

            foreach ($value as $key => $child) {
                $segment = (string) $key;
                $nextPath = $path === '' ? $segment : sprintf('%s.%s', $path, $segment);
                $this->flattenFinancialControlsSummary($child, $nextPath, $rows);
            }

            return;
        }

        if ($value === null) {
            $normalized = 'null';
        } elseif (is_bool($value)) {
            $normalized = $value ? 'true' : 'false';
        } elseif (is_scalar($value)) {
            $normalized = (string) $value;
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $normalized = $encoded === false ? '{}' : $encoded;
        }

        $rows[] = [$path === '' ? 'root' : $path, $normalized];
    }

    /**
     * @param  mixed  $lineItems
     * @return array<int, array<string, mixed>>|null
     */
    private function normalizeLineItems(mixed $lineItems): ?array
    {
        if (! is_array($lineItems)) {
            return null;
        }

        return array_values(array_map(function (array $item): array {
            $quantity = round((float) ($item['quantity'] ?? 0), 2);
            $unitPrice = round((float) ($item['unitPrice'] ?? 0), 2);
            $lineTotal = round(max($quantity, 0) * max($unitPrice, 0), 2);

            return [
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => max($quantity, 0),
                'unitPrice' => max($unitPrice, 0),
                'lineTotal' => $lineTotal,
                'serviceCode' => isset($item['serviceCode']) ? strtoupper(trim((string) $item['serviceCode'])) : null,
                'departmentId' => isset($item['departmentId']) ? trim((string) $item['departmentId']) : null,
                'department' => isset($item['department']) ? trim((string) $item['department']) : null,
                'unit' => $item['unit'] ?? null,
                'notes' => $item['notes'] ?? null,
                'sourceWorkflowKind' => isset($item['sourceWorkflowKind'])
                    ? strtolower(trim((string) $item['sourceWorkflowKind']))
                    : null,
                'sourceWorkflowId' => isset($item['sourceWorkflowId'])
                    ? trim((string) $item['sourceWorkflowId'])
                    : null,
                'sourceWorkflowLabel' => isset($item['sourceWorkflowLabel'])
                    ? trim((string) $item['sourceWorkflowLabel'])
                    : null,
                'sourcePerformedAt' => $item['sourcePerformedAt'] ?? null,
            ];
        }, $lineItems));
    }
}
