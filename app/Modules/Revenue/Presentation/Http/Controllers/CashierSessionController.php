<?php

namespace App\Modules\Revenue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Revenue\Application\Support\CashierSessionTotals;
use App\Modules\Revenue\Application\UseCases\ApproveCashierSessionVarianceUseCase;
use App\Modules\Revenue\Application\UseCases\CloseCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashMovementUseCase;
use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use App\Modules\Revenue\Domain\ValueObjects\CashMovementReason;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionModel;
use App\Modules\Revenue\Presentation\Http\Controllers\Concerns\RendersRevenueErrors;
use App\Modules\Revenue\Presentation\Http\Requests\CloseCashierSessionRequest;
use App\Modules\Revenue\Presentation\Http\Requests\OpenCashierSessionRequest;
use App\Modules\Revenue\Presentation\Http\Requests\ReasonedActionRequest;
use App\Modules\Revenue\Presentation\Http\Requests\RecordCashMovementRequest;
use App\Modules\Revenue\Presentation\Http\Transformers\CashierSessionResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierSessionController extends Controller
{
    use RendersRevenueErrors;

    /**
     * The signed-in cashier's open drawer, or null.
     *
     * Deliberately scoped to the caller rather than accepting a user id: one
     * cashier has no business reading another's till from this endpoint.
     */
    public function current(Request $request): JsonResponse
    {
        $session = CashierSessionModel::query()
            ->where('cashier_user_id', (int) $request->user()?->id)
            ->where('status', CashierSessionStatus::OPEN->value)
            ->first();

        return response()->json([
            'data' => $session === null ? null : CashierSessionResponseTransformer::transform($session),
        ]);
    }

    public function store(OpenCashierSessionRequest $request, OpenCashierSessionUseCase $useCase): JsonResponse
    {
        return $this->renderingRevenueErrors(function () use ($request, $useCase): JsonResponse {
            $session = $useCase->execute(
                cashierUserId: (int) $request->user()?->id,
                openingFloatMinor: (int) $request->integer('openingFloatMinor'),
                actorUserId: (int) $request->user()?->id,
            );

            return response()->json([
                'data' => CashierSessionResponseTransformer::transform($session),
            ], 201);
        });
    }

    public function recordMovement(
        string $id,
        RecordCashMovementRequest $request,
        RecordCashMovementUseCase $useCase,
    ): JsonResponse {
        return $this->renderingRevenueErrors(function () use ($id, $request, $useCase): JsonResponse {
            $useCase->execute(
                cashierSessionId: $id,
                reason: CashMovementReason::from($request->string('reason')->toString()),
                amountMinor: (int) $request->integer('amountMinor'),
                actorUserId: (int) $request->user()?->id,
                note: $request->input('note'),
            );

            $session = CashierSessionModel::query()->findOrFail($id);

            return response()->json([
                'data' => CashierSessionResponseTransformer::transform($session),
            ], 201);
        });
    }

    /**
     * Blind count in, variance out. The expected figure is computed only after
     * the count has been submitted, and is not readable before then.
     */
    public function close(
        string $id,
        CloseCashierSessionRequest $request,
        CloseCashierSessionUseCase $useCase,
    ): JsonResponse {
        return $this->renderingRevenueErrors(function () use ($id, $request, $useCase): JsonResponse {
            $result = $useCase->execute(
                cashierSessionId: $id,
                declaredCashMinor: (int) $request->integer('declaredCashMinor'),
                actorUserId: (int) $request->user()?->id,
            );

            return response()->json([
                'data' => CashierSessionResponseTransformer::transform($result['session']),
                'meta' => ['requiresApproval' => $result['requiresApproval']],
            ]);
        });
    }

    public function approveVariance(
        string $id,
        ReasonedActionRequest $request,
        ApproveCashierSessionVarianceUseCase $useCase,
    ): JsonResponse {
        return $this->renderingRevenueErrors(fn (): JsonResponse => response()->json([
            'data' => CashierSessionResponseTransformer::transform(
                $useCase->execute($id, (int) $request->user()?->id, $request->string('reason')->toString()),
            ),
        ]));
    }

    /**
     * The Z-report.
     *
     * Only for a drawer that has been counted: returning expected cash for an
     * open session would hand the cashier the number the blind count exists to
     * withhold.
     */
    public function summary(string $id, CashierSessionTotals $totals): JsonResponse
    {
        $session = CashierSessionModel::query()->find($id);

        abort_if($session === null, 404, 'Drawer not found.');

        if ($session->status === CashierSessionStatus::OPEN) {
            return response()->json([
                'message' => 'A drawer must be counted before its report is available.',
                'code' => 'CASHIER_SESSION_NOT_COUNTED',
            ], 409);
        }

        $computed = $totals->forSession($session);

        return response()->json([
            'data' => array_merge(
                CashierSessionResponseTransformer::transform($session),
                [
                    'cashTaken' => $computed['cashTaken']->toDecimalString(),
                    'reversals' => $computed['reversals']->toDecimalString(),
                    'refundsPaid' => $computed['refundsPaid']->toDecimalString(),
                    'cashIn' => $computed['cashIn']->toDecimalString(),
                    'cashOut' => $computed['cashOut']->toDecimalString(),
                    'paymentCount' => $computed['paymentCount'],
                ],
            ),
        ]);
    }
}
