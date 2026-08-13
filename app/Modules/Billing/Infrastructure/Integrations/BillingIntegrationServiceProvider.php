<?php

namespace App\Modules\Billing\Infrastructure\Integrations;

use App\Modules\Billing\Domain\Integrations\NhifClaimSubmissionInterface;
use App\Modules\Billing\Domain\Integrations\NhifRemittanceInterface;
use App\Modules\Billing\Domain\Integrations\NhifVerificationInterface;
use App\Modules\Billing\Domain\Integrations\PaymentGatewayInterface;
use App\Modules\Billing\Domain\Integrations\PaymentLinkInterface;
use App\Modules\Billing\Domain\Integrations\SmsProviderInterface;
use App\Modules\Billing\Domain\Integrations\TraFiscalReceiptInterface;
use App\Modules\Billing\Infrastructure\Integrations\NHIF\NhifClaimSubmission;
use App\Modules\Billing\Infrastructure\Integrations\NHIF\NhifMemberVerification;
use App\Modules\Billing\Infrastructure\Integrations\NHIF\NhifRemittanceProcessor;
use App\Modules\Billing\Infrastructure\Integrations\NHIF\NhifTariffSyncService;
use App\Modules\Billing\Infrastructure\Integrations\PaymentGateway\SelcomGateway;
use App\Modules\Billing\Infrastructure\Integrations\PaymentGateway\SelcomPaymentLinkService;
use App\Modules\Billing\Infrastructure\Integrations\Sms\AfricasTalkingSmsProvider;
use App\Modules\Billing\Infrastructure\Integrations\Sms\BillingSmsService;
use App\Modules\Billing\Infrastructure\Integrations\TRA\TotalVfdReceipt;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Support\ServiceProvider;

class BillingIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, function () {
            $driver = config('billing-integrations.payment_gateway.driver', 'selcom');

            return match ($driver) {
                default => new SelcomGateway,
            };
        });

        $this->app->bind(NhifVerificationInterface::class, function () {
            return new NhifMemberVerification;
        });

        $this->app->bind(TraFiscalReceiptInterface::class, function () {
            $provider = config('billing-integrations.tra_vfd.provider', 'totalvfd');

            return match ($provider) {
                default => new TRA\TotalVfdReceipt,
            };
        });

        $this->app->bind(NhifClaimSubmissionInterface::class, function () {
            return new NhifClaimSubmission;
        });

        $this->app->bind(PaymentLinkInterface::class, function () {
            return new SelcomPaymentLinkService;
        });

        $this->app->singleton(NhifTariffSyncService::class, function () {
            return new NhifTariffSyncService;
        });

        $this->app->singleton(NhifRemittanceProcessor::class, function () {
            return new NhifRemittanceProcessor;
        });

        $this->app->bind(SmsProviderInterface::class, function () {
            $driver = config('billing-integrations.sms.driver', 'africastalking');

            return match ($driver) {
                default => new AfricasTalkingSmsProvider,
            };
        });

        $this->app->bind(BillingSmsService::class, function ($app) {
            return new BillingSmsService(
                smsProvider: $app->make(SmsProviderInterface::class),
                scopeContext: $app->make(CurrentPlatformScopeContextInterface::class),
            );
        });

        $this->app->bind(BillingIntegrationService::class, function ($app) {
            return new BillingIntegrationService(
                paymentGateway: $app->make(PaymentGatewayInterface::class),
                nhifVerification: $app->make(NhifVerificationInterface::class),
                traReceipt: $app->make(TraFiscalReceiptInterface::class),
                tenantId: request()->route('tenant_id'),
                facilityId: request()->route('facility_id'),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
