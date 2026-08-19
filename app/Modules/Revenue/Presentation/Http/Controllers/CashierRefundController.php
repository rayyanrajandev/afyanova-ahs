<?php

namespace App\Modules\Revenue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Revenue\Application\UseCases\ApproveRefundUseCase;
use App\Modules\Revenue\Application\UseCases\RejectRefundUseCase;
use App\Modules\Revenue\Application\UseCases\RequestRefundUseCase;
use App\Modules\Revenue\Domain\ValueObjects\RefundStatus;
use App\Modules\Revenue\Infrastructure\Models\RefundModel;
use App\Modules\Revenue\Presentation\Http\Controllers\Concerns\RendersRevenueErrors;
use App\Modules\Revenue\Presentation\Http\Requests\ApproveRefundRequest;
use App\Modules\Revenue\Presentation\Http\Requests\ReasonedActionRequest;
use App\Modules\Revenue\Presentation\Http\Requests\RequestRefundRequest;
use App\Modules\Revenue\Presentation\Http\Transformers\RefundResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierRefundController extends Controller
{
    use RendersRevenueErrors;

    public function index(Request $request): JsonResponse
    {
        $refunds = RefundModel::query()
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')->toString()),
                fn ($q) => $q->where('status', RefundStatus::REQUESTED->value),
            )
            ->orderByDesc('requested_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $refunds->map([RefundResponseTransformer::class, 'transform'])->all(),
        ]);
    }

    public function store(RequestRefundRequest $request, RequestRefundUseCase $useCase): JsonResponse
    {
        return $this->renderingRevenueErrors(fn (): JsonResponse => response()->json([
            'data' => RefundResponseTransformer::transform($useCase->execute(
                paymentId: $request->string('paymentId')->toString(),
                amountMinor: (int) $request->integer('amountMinor'),
                reason: $request->string('reason')->toString(),
                requestedByUserId: (int) $request->user()?->id,
                serviceChargeId: $request->input('serviceChargeId'),
            )),
        ], 201));
    }

    public function reject(
        string $id,
        ReasonedActionRequest $request,
        RejectRefundUseCase $useCase,
    ): JsonResponse {
        return $this->renderingRevenueErrors(fn (): JsonResponse => response()->json([
            'data' => RefundResponseTransformer::transform($useCase->execute(
                refundId: $id,
                rejectedByUserId: (int) $request->user()?->id,
                reason: $request->string('reason')->toString(),
            )),
        ]));
    }

    public function approve(
        string $id,
        ApproveRefundRequest $request,
        ApproveRefundUseCase $useCase,
    ): JsonResponse {
        return $this->renderingRevenueErrors(fn (): JsonResponse => response()->json([
            'data' => RefundResponseTransformer::transform($useCase->execute(
                refundId: $id,
                approverUserId: (int) $request->user()?->id,
                paidFromSessionId: $request->string('paidFromSessionId')->toString(),
                note: $request->input('note'),
            )),
        ]));
    }
}
