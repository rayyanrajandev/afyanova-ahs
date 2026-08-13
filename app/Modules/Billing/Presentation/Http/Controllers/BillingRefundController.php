<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Application\Support\BillingFinancePostingSnapshotService;
use App\Modules\Billing\Application\UseCases\ApproveRefundUseCase;
use App\Modules\Billing\Application\UseCases\CreateRefundRequestUseCase;
use App\Modules\Billing\Application\UseCases\ProcessRefundUseCase;
use App\Modules\Billing\Domain\Repositories\BillingRefundRepositoryInterface;
use App\Modules\Billing\Presentation\Http\Concerns\RespondsWithBillingApi;
use App\Modules\Billing\Presentation\Http\Requests\ApproveBillingRefundRequest;
use App\Modules\Billing\Presentation\Http\Requests\CreateBillingRefundRequest;
use App\Modules\Billing\Presentation\Http\Requests\ListBillingRefundsRequest;
use App\Modules\Billing\Presentation\Http\Requests\ProcessBillingRefundRequest;
use App\Modules\Billing\Presentation\Http\Transformers\BillingRefundResponseTransformer;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Http\JsonResponse;

class BillingRefundController
{
    use RespondsWithBillingApi;

    public function __construct(
        private readonly CreateRefundRequestUseCase $createRefundUseCase,
        private readonly ApproveRefundUseCase $approveRefundUseCase,
        private readonly ProcessRefundUseCase $processRefundUseCase,
        private readonly BillingRefundRepositoryInterface $refundRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly BillingFinancePostingSnapshotService $financePostingSnapshotService,
    ) {}

    public function listRefunds(ListBillingRefundsRequest $request): JsonResponse
    {
        $refunds = $this->refundRepository->findForFacility(
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
            filters: $request->validated(),
        );

        return $this->successResponse(
            $this->transformRefundCollection($refunds),
        );
    }

    /**
     * Create a refund request
     */
    public function createRefund(CreateBillingRefundRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $actorId = auth()->id();

        try {
            $refund = $this->createRefundUseCase->execute($validated, $actorId);

            return $this->successResponse(
                data: $this->transformRefund($refund),
                status: 201,
            );
        } catch (\RuntimeException $e) {
            return $this->unprocessableResponse($e->getMessage(), 'BILLING_REFUND_CREATE_FAILED');
        }
    }

    /**
     * Approve a refund request
     */
    public function approveRefund(string $refundId, ApproveBillingRefundRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['refund_id'] = $refundId;
        $actorId = auth()->id();

        try {
            $refund = $this->approveRefundUseCase->execute($validated, $actorId);

            return $this->successResponse($this->transformRefund($refund));
        } catch (\RuntimeException $e) {
            return $this->unprocessableResponse($e->getMessage(), 'BILLING_REFUND_APPROVAL_FAILED');
        }
    }

    /**
     * Process a refund (actually send money)
     */
    public function processRefund(string $refundId, ProcessBillingRefundRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['refund_id'] = $refundId;
        $actorId = auth()->id();

        try {
            $refund = $this->processRefundUseCase->execute($validated, $actorId);

            return $this->successResponse($this->transformRefund($refund));
        } catch (\RuntimeException $e) {
            return $this->unprocessableResponse($e->getMessage(), 'BILLING_REFUND_PROCESS_FAILED');
        }
    }

    /**
     * Get a refund by ID
     */
    public function getRefund(string $refundId): JsonResponse
    {
        $refund = $this->refundRepository->findById($refundId);

        if ($refund === null) {
            return $this->notFoundResponse('Refund not found');
        }

        return $this->successResponse($this->transformRefund($refund));
    }

    /**
     * List pending refunds
     */
    public function listPendingRefunds(): JsonResponse
    {
        $refunds = $this->refundRepository->findForFacility(
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
            filters: ['status' => 'pending'],
        );

        return $this->successResponse(
            $this->transformRefundCollection($refunds),
        );
    }

    /**
     * Get refunds for an invoice
     */
    public function getInvoiceRefunds(string $invoiceId): JsonResponse
    {
        $refunds = $this->refundRepository->findByInvoiceId($invoiceId);

        return $this->successResponse(
            $this->transformRefundCollection($refunds),
        );
    }

    /**
     * @param array<string, mixed> $refund
     * @return array<string, mixed>
     */
    private function transformRefund(array $refund): array
    {
        $transformed = BillingRefundResponseTransformer::transform($refund);
        $transformed['financePosting'] = $this->financePostingSnapshotService->refundSummary((string) ($refund['id'] ?? ''));

        return $transformed;
    }

    /**
     * @param array<int, array<string, mixed>> $refunds
     * @return array<int, array<string, mixed>>
     */
    private function transformRefundCollection(array $refunds): array
    {
        $financeSummaries = $this->financePostingSnapshotService->refundSummaries(
            array_values(
                array_filter(
                    array_map(
                        static fn (array $refund): ?string => $refund['id'] ?? null,
                        $refunds,
                    ),
                ),
            ),
        );

        return array_map(function (array $refund) use ($financeSummaries): array {
            $transformed = BillingRefundResponseTransformer::transform($refund);
            $refundId = (string) ($refund['id'] ?? '');

            if ($refundId !== '') {
                $transformed['financePosting'] = $financeSummaries[$refundId]
                    ?? $this->financePostingSnapshotService->refundSummary($refundId);
            }

            return $transformed;
        }, $refunds);
    }
}
