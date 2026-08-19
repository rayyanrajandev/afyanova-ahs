<?php

namespace App\Modules\Revenue\Presentation\Http\Controllers\Concerns;

use App\Modules\Revenue\Domain\Exceptions\CashierSessionAlreadyOpenException;
use App\Modules\Revenue\Domain\Exceptions\CashierSessionRequiredException;
use App\Modules\Revenue\Domain\Exceptions\InsufficientTenderException;
use App\Modules\Revenue\Domain\Exceptions\PayerClassNotImplementedException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Turns domain refusals into responses a counter can act on.
 *
 * Each carries a machine-readable code so the workspace can offer the right
 * next step — "Open your drawer" rather than a red box the cashier can only
 * stare at.
 */
trait RendersRevenueErrors
{
    /**
     * @param  callable(): JsonResponse  $action
     */
    protected function renderingRevenueErrors(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (CashierSessionRequiredException $e) {
            return $this->revenueError($e, 'CASHIER_SESSION_REQUIRED', 409);
        } catch (CashierSessionAlreadyOpenException $e) {
            return $this->revenueError($e, 'CASHIER_SESSION_ALREADY_OPEN', 409);
        } catch (InsufficientTenderException $e) {
            return $this->revenueError($e, 'INSUFFICIENT_TENDER', 422, [
                'amountDue' => $e->due->toDecimalString(),
                'amountTendered' => $e->tendered->toDecimalString(),
                'currencyCode' => $e->due->currencyCode,
            ]);
        } catch (PayerClassNotImplementedException $e) {
            return $this->revenueError($e, 'PAYER_CLASS_NOT_IMPLEMENTED', 422, [
                'payerClass' => $e->payerClass->value,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->revenueError($e, 'REVENUE_REQUEST_INVALID', 422);
        } catch (RuntimeException $e) {
            return $this->revenueError($e, 'REVENUE_ACTION_NOT_ALLOWED', 409);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function revenueError(Throwable $e, string $code, int $status, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'message' => $e->getMessage(),
            'code' => $code,
        ], $extra), $status);
    }
}
