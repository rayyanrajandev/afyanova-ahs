<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Application\Support\BillingFinancePostingSnapshotService;
use App\Modules\Billing\Application\UseCases\ApplyDiscountToInvoiceUseCase;
use App\Modules\Billing\Application\UseCases\CreateDiscountPolicyUseCase;
use App\Modules\Billing\Domain\Repositories\BillingDiscountPolicyRepositoryInterface;
use App\Modules\Billing\Presentation\Http\Concerns\RespondsWithBillingApi;
use App\Modules\Billing\Presentation\Http\Requests\ApplyBillingDiscountRequest;
use App\Modules\Billing\Presentation\Http\Requests\ListBillingDiscountPoliciesRequest;
use App\Modules\Billing\Presentation\Http\Requests\StoreBillingDiscountPolicyRequest;
use App\Modules\Billing\Presentation\Http\Transformers\BillingDiscountApplicationResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingDiscountPolicyResponseTransformer;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Http\JsonResponse;

class BillingDiscountController
{
    use RespondsWithBillingApi;

    public function __construct(
        private readonly CreateDiscountPolicyUseCase $createPolicyUseCase,
        private readonly ApplyDiscountToInvoiceUseCase $applyDiscountUseCase,
        private readonly BillingDiscountPolicyRepositoryInterface $policyRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly BillingFinancePostingSnapshotService $financePostingSnapshotService,
    ) {}

    /**
     * Create a new discount policy
     */
    public function createPolicy(StoreBillingDiscountPolicyRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $actorId = auth()->id();
        $policy = $this->createPolicyUseCase->execute($validated, $actorId);

        return $this->successResponse(
            data: BillingDiscountPolicyResponseTransformer::transform($policy),
            status: 201,
        );
    }

    /**
     * List active discount policies for the facility
     */
    public function listPolicies(ListBillingDiscountPoliciesRequest $request): JsonResponse
    {
        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();

        $policies = $this->policyRepository->findForFacility($tenantId, $facilityId, $request->validated());

        return $this->successResponse(
            array_map([BillingDiscountPolicyResponseTransformer::class, 'transform'], $policies),
        );
    }

    /**
     * Get a specific discount policy
     */
    public function getPolicy(string $policyId): JsonResponse
    {
        $policy = $this->policyRepository->findById($policyId);

        if ($policy === null) {
            return $this->notFoundResponse('Discount policy not found');
        }

        return $this->successResponse(BillingDiscountPolicyResponseTransformer::transform($policy));
    }

    /**
     * Apply discount to an invoice
     */
    public function applyToInvoice(string $invoiceId, ApplyBillingDiscountRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['invoice_id'] = $invoiceId;
        $actorId = auth()->id();

        try {
            $discount = $this->applyDiscountUseCase->execute($validated, $actorId);

            return $this->successResponse(
                data: $this->transformDiscountApplication($discount),
                status: 201,
            );
        } catch (\RuntimeException $e) {
            return $this->unprocessableResponse($e->getMessage(), 'BILLING_DISCOUNT_ACTION_FAILED');
        }
    }

    public function applyDirect(ApplyBillingDiscountRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $actorId = auth()->id();

        try {
            $discount = $this->applyDiscountUseCase->execute($validated, $actorId);

            return $this->successResponse(
                data: $this->transformDiscountApplication($discount),
                status: 201,
            );
        } catch (\RuntimeException $e) {
            return $this->unprocessableResponse($e->getMessage(), 'BILLING_DISCOUNT_ACTION_FAILED');
        }
    }

    /**
     * @param array<string, mixed> $discount
     * @return array<string, mixed>
     */
    private function transformDiscountApplication(array $discount): array
    {
        $transformed = BillingDiscountApplicationResponseTransformer::transform($discount);
        $transformed['financePosting'] = $this->financePostingSnapshotService->discountApplicationSummary(
            $transformed['billing_invoice_id'] ?? null,
        );

        return $transformed;
    }
}
