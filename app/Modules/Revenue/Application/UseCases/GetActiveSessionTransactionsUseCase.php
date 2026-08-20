<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\PaymentStatus;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionModel;
use App\Modules\Revenue\Infrastructure\Models\PaymentModel;

class GetActiveSessionTransactionsUseCase
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
        private readonly DefaultCurrencyResolverInterface $currencyResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $cashierUserId): array
    {
        $facilityId = $this->scopeContext->facilityId();
        $currency = $this->currencyResolver->resolve();

        $sessionQuery = CashierSessionModel::query()
            ->where('cashier_user_id', $cashierUserId)
            ->where('status', CashierSessionStatus::OPEN->value);
            
        if ($facilityId !== null) {
            $sessionQuery->where('facility_id', $facilityId);
        }

        $session = $sessionQuery->first();

        if ($session === null) {
            return [
                'session' => null,
                'transactions' => [],
                'totalsByMethod' => [],
                'totalGross' => '0.00',
            ];
        }

        $payments = PaymentModel::query()
            ->with(['receipt'])
            ->where('cashier_session_id', $session->id)
            ->where('status', PaymentStatus::RECORDED->value)
            ->orderByDesc('received_at')
            ->get();

        $totalsByMethodMinor = [];
        $totalGrossMinor = 0;
        
        $transactions = $payments->map(function (PaymentModel $payment) use (&$totalsByMethodMinor, &$totalGrossMinor, $currency) {
            $metadata = $payment->metadata ?? [];
            $tenderLines = $metadata['tenderLines'] ?? [];
            
            $paymentMethodsUsed = [];
            
            if (!empty($tenderLines)) {
                foreach ($tenderLines as $line) {
                    $method = $line['method'] ?? 'unknown';
                    $amtMinor = (int) ($line['amountMinor'] ?? 0);
                    
                    $totalsByMethodMinor[$method] = ($totalsByMethodMinor[$method] ?? 0) + $amtMinor;
                    $paymentMethodsUsed[] = [
                        'method' => $method,
                        'amount' => Money::of($amtMinor, $currency)->toDecimalString(),
                        'reference' => $line['reference'] ?? null,
                    ];
                }
            } else {
                $method = $payment->method->value;
                $amtMinor = (int) $payment->amount_minor; 
                
                $totalsByMethodMinor[$method] = ($totalsByMethodMinor[$method] ?? 0) + $amtMinor;
                $paymentMethodsUsed[] = [
                    'method' => $method,
                    'amount' => Money::of($amtMinor, $currency)->toDecimalString(),
                    'reference' => $metadata['paymentReference'] ?? $metadata['phoneNumber'] ?? null,
                ];
            }
            
            $totalGrossMinor += (int) $payment->amount_minor;

            return [
                'id' => (string) $payment->id,
                'paymentNumber' => (string) $payment->payment_number,
                'patientId' => (string) $payment->patient_id,
                'receiptNumber' => $payment->receipt ? (string) $payment->receipt->receipt_number : null,
                'amount' => Money::of((int) $payment->amount_minor, $currency)->toDecimalString(),
                'receivedAt' => $payment->received_at?->toIso8601String(),
                'methods' => $paymentMethodsUsed,
            ];
        })->all();
        
        $totalsFormatted = [];
        foreach ($totalsByMethodMinor as $method => $amountMinor) {
            $totalsFormatted[$method] = Money::of($amountMinor, $currency)->toDecimalString();
        }

        return [
            'session' => [
                'id' => (string) $session->id,
                'sessionNumber' => (string) $session->session_number,
                'openedAt' => $session->opened_at?->toIso8601String(),
                'cashierName' => \App\Models\User::query()->find($session->cashier_user_id)?->name ?? 'Unknown',
            ],
            'transactions' => $transactions,
            'totalsByMethod' => $totalsFormatted,
            'totalGross' => Money::of($totalGrossMinor, $currency)->toDecimalString(),
            'currencyCode' => $currency,
        ];
    }
}
