<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\UseCases\CreateBillingCorporateAccountUseCase;
use App\Modules\Billing\Application\UseCases\CreateBillingCorporateInvoiceRunUseCase;
use App\Modules\Billing\Application\UseCases\GetBillingCorporateAccountUseCase;
use App\Modules\Billing\Application\UseCases\GetBillingCorporateInvoiceRunUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingCorporateAccountsUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingCorporateInvoiceRunsUseCase;
use App\Modules\Billing\Application\UseCases\RecordBillingCorporateRunPaymentUseCase;
use App\Modules\Billing\Presentation\Http\Concerns\RespondsWithBillingApi;
use App\Modules\Billing\Presentation\Http\Requests\ListBillingCorporateAccountsRequest;
use App\Modules\Billing\Presentation\Http\Requests\RecordBillingCorporateRunPaymentRequest;
use App\Modules\Billing\Presentation\Http\Requests\StoreBillingCorporateAccountRequest;
use App\Modules\Billing\Presentation\Http\Requests\StoreBillingCorporateInvoiceRunRequest;
use App\Modules\Billing\Presentation\Http\Transformers\BillingCorporateAccountResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingCorporateInvoiceRunResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BillingCorporateBillingController extends Controller
{
    use RespondsWithBillingApi;

    public function index(ListBillingCorporateAccountsRequest $request, ListBillingCorporateAccountsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->validated());

        return $this->successResponse(
            data: array_map([BillingCorporateAccountResponseTransformer::class, 'transform'], $result['data']),
            meta: $result['meta'],
        );
    }

    public function storeAccount(StoreBillingCorporateAccountRequest $request, CreateBillingCorporateAccountUseCase $useCase): JsonResponse
    {
        try {
            $account = $useCase->execute([
                'billing_payer_contract_id' => $request->input('billingPayerContractId'),
                'account_code' => $request->input('accountCode'),
                'account_name' => $request->input('accountName'),
                'billing_contact_name' => $request->input('billingContactName'),
                'billing_contact_email' => $request->input('billingContactEmail'),
                'billing_contact_phone' => $request->input('billingContactPhone'),
                'billing_cycle_day' => $request->input('billingCycleDay'),
                'settlement_terms_days' => $request->input('settlementTermsDays'),
                'status' => $request->input('status'),
                'notes' => $request->input('notes'),
            ]);
        } catch (RuntimeException $exception) {
            return $this->unprocessableResponse($exception->getMessage(), 'BILLING_CORPORATE_ACCOUNT_CREATE_FAILED');
        }

        return $this->successResponse(BillingCorporateAccountResponseTransformer::transform($account), 201);
    }

    public function showAccount(string $id, GetBillingCorporateAccountUseCase $useCase): JsonResponse
    {
        $account = $useCase->execute($id);
        if ($account === null) {
            return $this->notFoundResponse('Corporate billing account not found.');
        }

        return $this->successResponse(BillingCorporateAccountResponseTransformer::transform($account));
    }

    public function runs(string $accountId, Request $request, ListBillingCorporateInvoiceRunsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($accountId, $request->all());

        return $this->successResponse(
            data: array_map([BillingCorporateInvoiceRunResponseTransformer::class, 'transform'], $result['data']),
            meta: $result['meta'],
        );
    }

    public function storeRun(
        string $accountId,
        StoreBillingCorporateInvoiceRunRequest $request,
        CreateBillingCorporateInvoiceRunUseCase $useCase,
    ): JsonResponse {
        try {
            $run = $useCase->execute($accountId, [
                'billing_period_start' => $request->input('billingPeriodStart'),
                'billing_period_end' => $request->input('billingPeriodEnd'),
                'issue_date' => $request->input('issueDate'),
                'due_date' => $request->input('dueDate'),
                'notes' => $request->input('notes'),
            ], $request->user()?->id);
        } catch (RuntimeException $exception) {
            return $this->unprocessableResponse($exception->getMessage(), 'BILLING_CORPORATE_RUN_CREATE_FAILED');
        }

        return $this->successResponse(BillingCorporateInvoiceRunResponseTransformer::transform($run), 201);
    }

    public function showRun(string $runId, GetBillingCorporateInvoiceRunUseCase $useCase): JsonResponse
    {
        $run = $useCase->execute($runId);
        if ($run === null) {
            return $this->notFoundResponse('Corporate billing run not found.');
        }

        return $this->successResponse(BillingCorporateInvoiceRunResponseTransformer::transform($run));
    }

    public function recordRunPayment(
        string $runId,
        RecordBillingCorporateRunPaymentRequest $request,
        RecordBillingCorporateRunPaymentUseCase $useCase,
    ): JsonResponse {
        try {
            $run = $useCase->execute($runId, [
                'amount' => $request->input('amount'),
                'payment_method' => $request->input('paymentMethod'),
                'payment_reference' => $request->input('paymentReference'),
                'payment_at' => $request->input('paymentAt'),
                'note' => $request->input('note'),
            ], $request->user()?->id);
        } catch (RuntimeException $exception) {
            return $this->unprocessableResponse($exception->getMessage(), 'BILLING_CORPORATE_RUN_PAYMENT_FAILED');
        }

        if ($run === null) {
            return $this->notFoundResponse('Corporate billing run not found.');
        }

        return $this->successResponse(BillingCorporateInvoiceRunResponseTransformer::transform($run));
    }
}
