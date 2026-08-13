<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\UseCases\CreateBillingPaymentPlanUseCase;
use App\Modules\Billing\Application\UseCases\GetBillingPaymentPlanUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingPaymentPlansUseCase;
use App\Modules\Billing\Application\UseCases\RecordBillingPaymentPlanPaymentUseCase;
use App\Modules\Billing\Presentation\Http\Concerns\RespondsWithBillingApi;
use App\Modules\Billing\Presentation\Http\Requests\ListBillingPaymentPlansRequest;
use App\Modules\Billing\Presentation\Http\Requests\RecordBillingPaymentPlanPaymentRequest;
use App\Modules\Billing\Presentation\Http\Requests\StoreBillingPaymentPlanRequest;
use App\Modules\Billing\Presentation\Http\Transformers\BillingPaymentPlanResponseTransformer;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class BillingPaymentPlanController extends Controller
{
    use RespondsWithBillingApi;

    public function index(ListBillingPaymentPlansRequest $request, ListBillingPaymentPlansUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->validated());

        return $this->successResponse(
            data: array_map([BillingPaymentPlanResponseTransformer::class, 'transform'], $result['data']),
            meta: $result['meta'],
        );
    }

    public function store(StoreBillingPaymentPlanRequest $request, CreateBillingPaymentPlanUseCase $useCase): JsonResponse
    {
        try {
            $plan = $useCase->execute([
                'billing_invoice_id' => $request->input('billingInvoiceId'),
                'cash_billing_account_id' => $request->input('cashBillingAccountId'),
                'plan_name' => $request->input('planName'),
                'total_amount' => $request->input('totalAmount'),
                'down_payment_amount' => $request->input('downPaymentAmount'),
                'down_payment_payment_method' => $request->input('downPaymentPaymentMethod'),
                'down_payment_reference' => $request->input('downPaymentReference'),
                'down_payment_paid_at' => $request->input('downPaymentPaidAt'),
                'payer_type' => $request->input('payerType'),
                'installment_count' => $request->integer('installmentCount'),
                'installment_frequency' => $request->string('installmentFrequency')->value(),
                'installment_interval_days' => $request->input('installmentIntervalDays'),
                'first_due_date' => $request->string('firstDueDate')->value(),
                'terms_and_notes' => $request->input('termsAndNotes'),
            ], $request->user()?->id);
        } catch (RuntimeException $exception) {
            return $this->unprocessableResponse($exception->getMessage(), 'BILLING_PAYMENT_PLAN_SETUP_FAILED');
        }

        return $this->successResponse(BillingPaymentPlanResponseTransformer::transform($plan), 201);
    }

    public function show(string $id, GetBillingPaymentPlanUseCase $useCase): JsonResponse
    {
        $plan = $useCase->execute($id);
        if ($plan === null) {
            return $this->notFoundResponse('Billing payment plan not found.');
        }

        return $this->successResponse(BillingPaymentPlanResponseTransformer::transform($plan));
    }

    public function recordPayment(
        string $id,
        RecordBillingPaymentPlanPaymentRequest $request,
        RecordBillingPaymentPlanPaymentUseCase $useCase,
    ): JsonResponse {
        try {
            $plan = $useCase->execute($id, [
                'amount' => $request->input('amount'),
                'payer_type' => $request->input('payerType'),
                'payment_method' => $request->input('paymentMethod'),
                'payment_reference' => $request->input('paymentReference'),
                'payment_at' => $request->input('paymentAt'),
                'note' => $request->input('note'),
            ], $request->user()?->id);
        } catch (RuntimeException $exception) {
            return $this->unprocessableResponse($exception->getMessage(), 'BILLING_PAYMENT_PLAN_PAYMENT_FAILED');
        }

        if ($plan === null) {
            return $this->notFoundResponse('Billing payment plan not found.');
        }

        return $this->successResponse(BillingPaymentPlanResponseTransformer::transform($plan));
    }
}
