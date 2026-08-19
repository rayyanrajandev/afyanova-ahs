<?php

namespace App\Modules\Revenue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Revenue\Application\UseCases\RecordCashPaymentUseCase;
use App\Modules\Revenue\Application\UseCases\ReprintReceiptUseCase;
use App\Modules\Revenue\Application\UseCases\ReverseCashPaymentUseCase;
use App\Modules\Revenue\Infrastructure\Models\PaymentModel;
use App\Modules\Revenue\Infrastructure\Models\ReceiptModel;
use App\Modules\Revenue\Presentation\Http\Controllers\Concerns\RendersRevenueErrors;
use App\Modules\Revenue\Presentation\Http\Requests\ReasonedActionRequest;
use App\Modules\Revenue\Presentation\Http\Requests\RecordCashPaymentRequest;
use App\Modules\Revenue\Presentation\Http\Transformers\PaymentResponseTransformer;
use App\Modules\Revenue\Presentation\Http\Transformers\ReceiptResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierPaymentController extends Controller
{
    use RendersRevenueErrors;

    public function store(RecordCashPaymentRequest $request, RecordCashPaymentUseCase $useCase): JsonResponse
    {
        return $this->renderingRevenueErrors(function () use ($request, $useCase): JsonResponse {
            $payment = $useCase->execute(
                patientId: $request->string('patientId')->toString(),
                serviceChargeIds: array_values($request->array('serviceChargeIds')),
                tenderedAmountMinor: (int) $request->integer('tenderedAmountMinor'),
                idempotencyKey: $request->string('idempotencyKey')->toString(),
                cashierUserId: (int) $request->user()?->id,
                cashierSessionId: $request->input('cashierSessionId'),
            );

            return response()->json([
                'data' => PaymentResponseTransformer::transform($payment),
            ], 201);
        });
    }

    public function show(string $id): JsonResponse
    {
        $payment = PaymentModel::query()->with(['allocations', 'receipt'])->find($id);

        abort_if($payment === null, 404, 'Payment not found.');

        return response()->json(['data' => PaymentResponseTransformer::transform($payment)]);
    }

    public function reverse(
        string $id,
        ReasonedActionRequest $request,
        ReverseCashPaymentUseCase $useCase,
    ): JsonResponse {
        return $this->renderingRevenueErrors(function () use ($id, $request, $useCase): JsonResponse {
            $reversal = $useCase->execute(
                paymentId: $id,
                reason: $request->string('reason')->toString(),
                actorUserId: (int) $request->user()?->id,
            );

            return response()->json([
                'data' => PaymentResponseTransformer::transform($reversal->fresh(['allocations', 'receipt'])),
            ]);
        });
    }

    public function showReceipt(string $id): JsonResponse
    {
        $receipt = ReceiptModel::query()->find($id);

        abort_if($receipt === null, 404, 'Receipt not found.');

        return response()->json(['data' => ReceiptResponseTransformer::transform($receipt)]);
    }

    public function reprintReceipt(string $id, Request $request, ReprintReceiptUseCase $useCase): JsonResponse
    {
        return $this->renderingRevenueErrors(fn (): JsonResponse => response()->json([
            'data' => ReceiptResponseTransformer::transform(
                $useCase->execute($id, (int) $request->user()?->id, $request->input('reason')),
            ),
        ]));
    }
}
