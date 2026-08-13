<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Application\Listeners\SyncBillingSourceStatusProjection;
use App\Modules\Billing\Domain\Events\InvoicePaymentRecorded;
use App\Modules\Billing\Domain\Events\InvoicePaymentReversed;
use App\Modules\Billing\Domain\Events\InvoiceStatusChanged;
use App\Modules\Billing\Infrastructure\Integrations\BillingIntegrationServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(BillingIntegrationServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/billing-phase1.php'));

        // billing-financial-state-remediation-plan.md, Phase 2 -- keeps
        // billing_source_status current for any future shared "is this billed"
        // resolver, without any of the three write use cases needing to know
        // about the projection table themselves.
        Event::listen(
            InvoiceStatusChanged::class,
            [SyncBillingSourceStatusProjection::class, 'handleInvoiceStatusChanged'],
        );
        Event::listen(
            InvoicePaymentRecorded::class,
            [SyncBillingSourceStatusProjection::class, 'handleInvoicePaymentRecorded'],
        );
        Event::listen(
            InvoicePaymentReversed::class,
            [SyncBillingSourceStatusProjection::class, 'handleInvoicePaymentReversed'],
        );
    }
}
