<?php

namespace App\Modules\Revenue;

use App\Modules\Revenue\Domain\Services\ChargeAuthorizationPolicyResolverInterface;
use App\Modules\Revenue\Domain\Services\ChargeResolverInterface;
use App\Modules\Revenue\Domain\Services\DocumentNumberAllocatorInterface;
use App\Modules\Revenue\Domain\Services\OutstandingBalanceReaderInterface;
use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Infrastructure\Policies\ChargeAuthorizationPolicyResolver;
use App\Modules\Revenue\Infrastructure\Services\ChargeResolver;
use App\Modules\Revenue\Infrastructure\Services\DocumentNumberAllocator;
use App\Modules\Revenue\Infrastructure\Services\LedgerOutstandingBalanceReader;
use App\Modules\Revenue\Infrastructure\Services\RevenueAuditRecorder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * The prepaid revenue ledger.
 *
 * Phase 3 adds charges and pricing. Payments, receipts, refunds and cashier
 * sessions arrive in Phase 4; the HTTP surface in Phase 6.
 */
class RevenueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 3 replaces the Phase 1 null reader: there is a ledger now, so
        // "does this patient owe anything" has a real answer. No consumer
        // changed — that was the point of routing them through the contract.
        $this->app->singleton(
            OutstandingBalanceReaderInterface::class,
            LedgerOutstandingBalanceReader::class,
        );

        $this->app->singleton(ChargeResolverInterface::class, ChargeResolver::class);
        $this->app->singleton(RevenueAuditRecorderInterface::class, RevenueAuditRecorder::class);

        // The payer-extensibility seam: one policy today, keyed by payer
        // class. Adding an insurer registers another and changes nothing else.
        $this->app->singleton(
            ChargeAuthorizationPolicyResolverInterface::class,
            ChargeAuthorizationPolicyResolver::class,
        );

        $this->app->bind(
            DocumentNumberAllocatorInterface::class,
            fn (): DocumentNumberAllocator => new DocumentNumberAllocator(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        $this->app->bindIf(ConnectionInterface::class, fn () => DB::connection());
    }
}
