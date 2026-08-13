<?php

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Http\Middleware\ResolvePlatformScopeContext;
use App\Modules\Billing\Presentation\Http\Controllers\BillingDailyCloseController;
use App\Modules\Billing\Presentation\Http\Controllers\BillingDiscountController;
use App\Modules\Billing\Presentation\Http\Controllers\ConsultationMappingController;
use App\Modules\Billing\Presentation\Http\Controllers\BillingInvoiceController;
use App\Modules\Billing\Presentation\Http\Controllers\BillingRefundController;
use App\Modules\Billing\Presentation\Http\Controllers\BillingPatientWorkspaceController;
use App\Modules\Billing\Presentation\Http\Controllers\BillingRoutingController;
use App\Modules\Billing\Presentation\Http\Controllers\BillingWriteOffController;
use App\Modules\Billing\Presentation\Http\Controllers\CashBillingController;
use App\Modules\Billing\Presentation\Http\Controllers\ChargeableItemController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', ResolvePlatformScopeContext::class, EnforceTenantIsolationWhenEnabled::class, EnsureMappedFacilitySubscriptionEntitlement::class])
    ->prefix('api/v1')
    ->group(function () {
    // Cash Billing Routes
    Route::prefix('cash-patients')->group(function () {
        Route::get('/', [CashBillingController::class, 'index'])
            ->middleware('can:billing.cash-accounts.read')
            ->name('cash-billing.index');
        Route::post('/', [CashBillingController::class, 'createAccount'])
            ->middleware('can:billing.cash-accounts.manage')
            ->name('cash-billing.create-account');
        Route::get('{accountId}', [CashBillingController::class, 'getAccount'])
            ->middleware('can:billing.cash-accounts.read')
            ->name('cash-billing.get-account');
        Route::get('{accountId}/balance', [CashBillingController::class, 'getBalance'])
            ->middleware('can:billing.cash-accounts.read')
            ->name('cash-billing.get-balance');
        Route::post('{accountId}/charges', [CashBillingController::class, 'recordCharge'])
            ->middleware('can:billing.cash-accounts.manage')
            ->name('cash-billing.record-charge');
        Route::post('{accountId}/payments', [CashBillingController::class, 'recordPayment'])
            ->middleware('can:billing.cash-accounts.manage')
            ->name('cash-billing.record-payment');
        Route::post('{accountId}/convert-to-invoice', [CashBillingController::class, 'convertToInvoice'])
            ->middleware('can:billing.cash-accounts.manage')
            ->name('cash-billing.convert-to-invoice');
        Route::post('{accountId}/void', [CashBillingController::class, 'voidAccount'])
            ->middleware('can:billing.cash-accounts.manage')
            ->name('cash-billing.void');
        Route::post('{accountId}/refund', [CashBillingController::class, 'refundPayment'])
            ->middleware('can:billing.cash-accounts.manage')
            ->name('cash-billing.refund');
    });

    // Discount Routes
    Route::prefix('discount-policies')->group(function () {
        Route::post('/', [BillingDiscountController::class, 'createPolicy'])
            ->middleware('can:billing.discounts.manage')
            ->name('discounts.create-policy');
        Route::get('/', [BillingDiscountController::class, 'listPolicies'])
            ->middleware('can:billing.discounts.read')
            ->name('discounts.list-policies');
        Route::get('{policyId}', [BillingDiscountController::class, 'getPolicy'])
            ->middleware('can:billing.discounts.read')
            ->name('discounts.get-policy');
    });

    // Consultation Mappings (clinician tier + department -> billing service catalog item)
    Route::prefix('consultation-mappings')->group(function () {
        Route::get('/', [ConsultationMappingController::class, 'index'])
            ->middleware('can:billing.consultation-mappings.read')
            ->name('consultation-mappings.index');
        Route::post('/', [ConsultationMappingController::class, 'store'])
            ->middleware('can:billing.consultation-mappings.manage')
            ->name('consultation-mappings.store');
        Route::patch('{mappingId}', [ConsultationMappingController::class, 'update'])
            ->middleware('can:billing.consultation-mappings.manage')
            ->name('consultation-mappings.update');
        Route::delete('{mappingId}', [ConsultationMappingController::class, 'destroy'])
            ->middleware('can:billing.consultation-mappings.manage')
            ->name('consultation-mappings.destroy');
    });

    // Pricing engine v2: chargeable items + price book entries
    Route::prefix('chargeable-items')->group(function () {
        Route::get('/', [ChargeableItemController::class, 'index'])
            ->middleware('can:billing.chargeable-items.read')
            ->name('chargeable-items.index');
        Route::post('/', [ChargeableItemController::class, 'store'])
            ->middleware('can:billing.chargeable-items.manage')
            ->name('chargeable-items.store');
        Route::get('{chargeableItemId}', [ChargeableItemController::class, 'show'])
            ->middleware('can:billing.chargeable-items.read')
            ->name('chargeable-items.show');
        Route::patch('{chargeableItemId}', [ChargeableItemController::class, 'update'])
            ->middleware('can:billing.chargeable-items.manage')
            ->name('chargeable-items.update');
        Route::post('{chargeableItemId}/prices', [ChargeableItemController::class, 'storePrice'])
            ->middleware('can:billing.chargeable-items.manage')
            ->name('chargeable-items.store-price');
        Route::patch('{chargeableItemId}/prices/{priceId}', [ChargeableItemController::class, 'updatePrice'])
            ->middleware('can:billing.chargeable-items.manage')
            ->name('chargeable-items.update-price');
    });

    // Apply discounts
    Route::post('invoices/{invoiceId}/apply-discount', [BillingDiscountController::class, 'applyToInvoice'])
        ->middleware('can:billing.discounts.manage')
        ->name('discounts.apply-to-invoice');
    Route::post('discount-applications', [BillingDiscountController::class, 'applyDirect'])
        ->middleware('can:billing.discounts.manage')
        ->name('discounts.apply-direct');

    // Refunds
    Route::prefix('billing-refunds')->group(function () {
        Route::get('/', [BillingRefundController::class, 'listRefunds'])
            ->middleware('can:billing.refunds.read')
            ->name('billing-refunds.index');
        Route::post('/', [BillingRefundController::class, 'createRefund'])
            ->middleware('can:billing.refunds.create')
            ->name('billing-refunds.create');
        Route::get('pending', [BillingRefundController::class, 'listPendingRefunds'])
            ->middleware('can:billing.refunds.read')
            ->name('billing-refunds.pending');
        Route::get('{refundId}', [BillingRefundController::class, 'getRefund'])
            ->middleware('can:billing.refunds.read')
            ->name('billing-refunds.show');
        Route::post('{refundId}/approve', [BillingRefundController::class, 'approveRefund'])
            ->middleware('can:billing.refunds.approve')
            ->name('billing-refunds.approve');
        Route::post('{refundId}/process', [BillingRefundController::class, 'processRefund'])
            ->middleware('can:billing.refunds.process')
            ->name('billing-refunds.process');
    });

    Route::get('billing/{invoiceId}/refunds', [BillingRefundController::class, 'getInvoiceRefunds'])
        ->middleware('can:billing.refunds.read')
        ->name('billing-refunds.invoice-index');

    // Adjustments (Credit/Debit Notes)
    Route::post('invoices/{invoiceId}/adjustments', [BillingInvoiceController::class, 'addAdjustment'])
        ->middleware('can:billing.invoices.adjust')
        ->name('invoices.add-adjustment');
    Route::get('invoices/{invoiceId}/adjustments', [BillingInvoiceController::class, 'listAdjustments'])
        ->middleware('can:billing.invoices.read')
        ->name('invoices.list-adjustments');

    // Write-Offs
    Route::prefix('write-offs')->group(function () {
        Route::get('/', [BillingWriteOffController::class, 'index'])
            ->middleware('can:billing.invoices.read')
            ->name('write-offs.index');
        Route::post('/', [BillingWriteOffController::class, 'store'])
            ->middleware('can:billing.invoices.create')
            ->name('write-offs.store');
        Route::get('{writeOffId}', [BillingWriteOffController::class, 'show'])
            ->middleware('can:billing.invoices.read')
            ->name('write-offs.show');
        Route::post('{writeOffId}/approve', [BillingWriteOffController::class, 'approve'])
            ->middleware('can:billing.write-offs.approve')
            ->name('write-offs.approve');
    });

    // AR Aging Report
    Route::get('aging-report', [BillingInvoiceController::class, 'agingReport'])
        ->middleware('can:billing.financial-controls.read')
        ->name('aging-report.show');

    // AR Aging Report — async CSV export (large report, don't run in-request)
    Route::post('aging-report/export-jobs', [BillingInvoiceController::class, 'agingReportExportJobs'])
        ->middleware('can:billing.financial-controls.read')
        ->name('aging-report.export-jobs.create');
    Route::get('aging-report/export-jobs/{jobId}', [BillingInvoiceController::class, 'agingReportExportJob'])
        ->middleware('can:billing.financial-controls.read')
        ->name('aging-report.export-jobs.show');
    Route::get('aging-report/export-jobs/{jobId}/download', [BillingInvoiceController::class, 'downloadAgingReportExportJob'])
        ->middleware('can:billing.financial-controls.read')
        ->name('aging-report.export-jobs.download');

    // Daily Revenue Close
    Route::prefix('daily-closes')->group(function () {
        Route::get('/', [BillingDailyCloseController::class, 'index'])
            ->middleware('can:billing.financial-controls.read')
            ->name('daily-closes.index');
        Route::post('/', [BillingDailyCloseController::class, 'store'])
            ->middleware('can:billing.financial-controls.read')
            ->name('daily-closes.store');
    });

    // Billing routing
    Route::post('billing-routing/determine', [BillingRoutingController::class, 'determine'])
        ->middleware('can:billing.routing.read')
        ->name('billing-routing.determine');

    // Payment Gateway (Selcom)
    Route::prefix('billing-payments/gateway')->group(function () {
        Route::post('initiate', [\App\Modules\Billing\Presentation\Http\Controllers\BillingPaymentGatewayController::class, 'initiatePayment'])
            ->middleware('can:billing.payments.record')
            ->name('billing-payments.gateway.initiate');
        Route::get('status/{transactionReference}', [\App\Modules\Billing\Presentation\Http\Controllers\BillingPaymentGatewayController::class, 'checkStatus'])
            ->middleware('can:billing.payments.read')
            ->name('billing-payments.gateway.status');
        Route::post('confirm', [\App\Modules\Billing\Presentation\Http\Controllers\BillingPaymentGatewayController::class, 'confirmAndRecordPayment'])
            ->middleware('can:billing.payments.record')
            ->name('billing-payments.gateway.confirm');
    });

    // NHIF Verification
    Route::prefix('billing-nhif')->group(function () {
        Route::get('verify/{memberId}', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifVerificationController::class, 'verifyMember'])
            ->middleware('can:billing.insurance.read')
            ->name('billing-nhif.verify-member');
        Route::post('verify-card', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifVerificationController::class, 'verifyCard'])
            ->middleware('can:billing.insurance.read')
            ->name('billing-nhif.verify-card');
        Route::get('history', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifVerificationController::class, 'verificationHistory'])
            ->middleware('can:billing.insurance.read')
            ->name('billing-nhif.history');
    });

    // TRA Fiscal Receipts
    Route::prefix('billing-receipts')->group(function () {
        Route::post('issue', [\App\Modules\Billing\Presentation\Http\Controllers\BillingTraReceiptController::class, 'issueReceipt'])
            ->middleware('can:billing.payments.record')
            ->name('billing-receipts.issue');
        Route::get('{rctvnum}', [\App\Modules\Billing\Presentation\Http\Controllers\BillingTraReceiptController::class, 'getReceipt'])
            ->middleware('can:billing.payments.read')
            ->name('billing-receipts.show');
        Route::get('invoice/{billingInvoiceId}', [\App\Modules\Billing\Presentation\Http\Controllers\BillingTraReceiptController::class, 'getReceiptsForInvoice'])
            ->middleware('can:billing.payments.read')
            ->name('billing-receipts.invoice');
    });

    // === Phase 2: NHIF e-Claims, M-Pesa Self-Payment, Tariff Sync ===

    // NHIF e-Claims submission
    Route::prefix('billing-nhif/claims')->group(function () {
        Route::post('cases/{caseId}/submit', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifClaimController::class, 'submitClaim'])
            ->middleware('can:billing.insurance.manage')
            ->name('billing-nhif.claims.submit');
        Route::get('submissions/{submissionId}/status', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifClaimController::class, 'checkStatus'])
            ->middleware('can:billing.insurance.read')
            ->name('billing-nhif.claims.status');
        Route::get('submissions', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifClaimController::class, 'submissionHistory'])
            ->middleware('can:billing.insurance.read')
            ->name('billing-nhif.claims.history');
    });

    // M-Pesa self-payment (payment link / push)
    Route::prefix('billing-payments/mpesa')->group(function () {
        Route::post('initiate', [\App\Modules\Billing\Presentation\Http\Controllers\BillingPaymentLinkController::class, 'initiatePayment'])
            ->middleware('can:billing.payments.record')
            ->name('billing-payments.mpesa.initiate');
        Route::get('status/{referenceCode}', [\App\Modules\Billing\Presentation\Http\Controllers\BillingPaymentLinkController::class, 'checkPaymentStatus'])
            ->middleware('can:billing.payments.read')
            ->name('billing-payments.mpesa.status');
        Route::get('links', [\App\Modules\Billing\Presentation\Http\Controllers\BillingPaymentLinkController::class, 'listPaymentLinks'])
            ->middleware('can:billing.payments.read')
            ->name('billing-payments.mpesa.links');
    });

    // NHIF tariff schedule sync
    Route::prefix('billing-nhif/tariffs')->group(function () {
        Route::get('preview', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifTariffController::class, 'preview'])
            ->middleware('can:billing.insurance.read')
            ->name('billing-nhif.tariffs.preview');
        Route::post('import', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifTariffController::class, 'import'])
            ->middleware('can:billing.insurance.manage')
            ->name('billing-nhif.tariffs.import');
        Route::get('history', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifTariffController::class, 'importHistory'])
            ->middleware('can:billing.insurance.read')
            ->name('billing-nhif.tariffs.history');
        Route::get('catalog', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifTariffController::class, 'catalogItems'])
            ->middleware('can:billing.insurance.read')
            ->name('billing-nhif.tariffs.catalog');
    });

    // === Phase 3: NHIF Remittance Processor & SMS Integration ===

    // NHIF remittance (payment advice) file upload and reconciliation
    Route::prefix('billing-nhif/remittances')->group(function () {
        Route::post('upload', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifRemittanceController::class, 'upload'])
            ->middleware('can:billing.insurance.manage')
            ->name('billing-nhif.remittances.upload');
        Route::get('history', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifRemittanceController::class, 'history'])
            ->middleware('can:billing.insurance.read')
            ->name('billing-nhif.remittances.history');
        Route::get('{remittanceId}', [\App\Modules\Billing\Presentation\Http\Controllers\BillingNhifRemittanceController::class, 'show'])
            ->middleware('can:billing.insurance.read')
            ->name('billing-nhif.remittances.show');
    });

    // SMS notifications for payments and receipts
    Route::prefix('billing-sms')->group(function () {
        Route::post('payment-link', [\App\Modules\Billing\Presentation\Http\Controllers\BillingSmsController::class, 'sendPaymentLinkSms'])
            ->middleware('can:billing.payments.record')
            ->name('billing-sms.payment-link');
        Route::post('receipt', [\App\Modules\Billing\Presentation\Http\Controllers\BillingSmsController::class, 'sendReceiptSms'])
            ->middleware('can:billing.payments.record')
            ->name('billing-sms.receipt');
        Route::post('custom', [\App\Modules\Billing\Presentation\Http\Controllers\BillingSmsController::class, 'sendCustomSms'])
            ->middleware('can:billing.payments.record')
            ->name('billing-sms.custom');
        Route::get('log', [\App\Modules\Billing\Presentation\Http\Controllers\BillingSmsController::class, 'smsLog'])
            ->middleware('can:billing.payments.read')
            ->name('billing-sms.log');
    });

    // Patient Billing Workspace
    Route::get('billing/{patientId}/workspace', BillingPatientWorkspaceController::class)
        ->middleware('can:billing.invoices.read')
        ->name('billing.workspace.api');
    Route::get('billing/{patientId}/payments', [BillingPatientWorkspaceController::class, 'payments'])
        ->middleware('can:billing.invoices.read')
        ->name('billing.workspace.payments');
    Route::get('billing/{patientId}/audit-logs', [BillingPatientWorkspaceController::class, 'auditLogs'])
        ->middleware('can:billing.invoices.read')
        ->name('billing.workspace.audit-logs');
});
