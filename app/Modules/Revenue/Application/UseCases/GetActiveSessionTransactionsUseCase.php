<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\PaymentMethod;
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
                'totalsByMethodBreakdown' => [],
                'summary' => [
                    'totalGross' => '0.00',
                    'totalCash' => '0.00',
                    'totalDigital' => '0.00',
                    'totalTransactions' => 0,
                    'uniquePatientsCount' => 0,
                    'receiptsCount' => 0,
                ],
                'totalGross' => '0.00',
                'currencyCode' => $currency,
            ];
        }

        $payments = PaymentModel::query()
            ->with(['receipt'])
            ->where('cashier_session_id', $session->id)
            ->where('status', PaymentStatus::RECORDED->value)
            ->orderByDesc('received_at')
            ->get();

        $patientIds = $payments->pluck('patient_id')->filter()->unique();
        $patients = PatientModel::query()
            ->whereIn('id', $patientIds)
            ->get(['id', 'patient_number', 'first_name', 'middle_name', 'last_name', 'phone'])
            ->keyBy('id');

        $totalsByMethodMinor = [];
        $countsByMethod = [];
        $totalGrossMinor = 0;
        $totalCashMinor = 0;

        $transactions = $payments->map(function (PaymentModel $payment) use (
            &$totalsByMethodMinor,
            &$countsByMethod,
            &$totalGrossMinor,
            &$totalCashMinor,
            $patients,
            $currency
        ) {
            $metadata = $payment->metadata ?? [];
            $tenderLines = $metadata['tenderLines'] ?? [];

            $paymentMethodsUsed = [];

            if (!empty($tenderLines)) {
                foreach ($tenderLines as $line) {
                    $method = (string) ($line['method'] ?? 'unknown');
                    $amtMinor = (int) ($line['amountMinor'] ?? 0);

                    // If change was given from a cash line, calculate net retained cash
                    if ($method === PaymentMethod::CASH->value && (int) $payment->change_amount_minor > 0) {
                        $amtMinor = max(0, $amtMinor - (int) $payment->change_amount_minor);
                    }

                    $totalsByMethodMinor[$method] = ($totalsByMethodMinor[$method] ?? 0) + $amtMinor;
                    $countsByMethod[$method] = ($countsByMethod[$method] ?? 0) + 1;

                    if ($method === PaymentMethod::CASH->value) {
                        $totalCashMinor += $amtMinor;
                    }

                    $paymentMethodsUsed[] = [
                        'method' => $method,
                        'label' => self::methodLabel($method),
                        'amount' => Money::of($amtMinor, $currency)->toDecimalString(),
                        'reference' => $line['reference'] ?? null,
                    ];
                }
            } else {
                $method = $payment->method->value;
                $amtMinor = (int) $payment->amount_minor;

                $totalsByMethodMinor[$method] = ($totalsByMethodMinor[$method] ?? 0) + $amtMinor;
                $countsByMethod[$method] = ($countsByMethod[$method] ?? 0) + 1;

                if ($method === PaymentMethod::CASH->value) {
                    $totalCashMinor += $amtMinor;
                }

                $paymentMethodsUsed[] = [
                    'method' => $method,
                    'label' => self::methodLabel($method),
                    'amount' => Money::of($amtMinor, $currency)->toDecimalString(),
                    'reference' => $metadata['paymentReference'] ?? $metadata['phoneNumber'] ?? null,
                ];
            }

            $totalGrossMinor += (int) $payment->amount_minor;

            $patient = $payment->patient_id ? $patients->get($payment->patient_id) : null;
            $patientName = $patient
                ? trim(implode(' ', array_filter([
                    $patient->first_name,
                    $patient->middle_name,
                    $patient->last_name,
                ])))
                : null;

            return [
                'id' => (string) $payment->id,
                'paymentNumber' => (string) $payment->payment_number,
                'patientId' => (string) $payment->patient_id,
                'patient' => $patient ? [
                    'id' => (string) $patient->id,
                    'patientNumber' => (string) $patient->patient_number,
                    'name' => $patientName ?: 'Unknown',
                    'phone' => $patient->phone,
                ] : null,
                'patientName' => $patientName ?: 'Unknown Patient',
                'patientNumber' => $patient ? (string) $patient->patient_number : null,
                'receiptNumber' => $payment->receipt ? (string) $payment->receipt->receipt_number : null,
                'amount' => Money::of((int) $payment->amount_minor, $currency)->toDecimalString(),
                'tenderedAmount' => Money::of((int) $payment->tendered_amount_minor, $currency)->toDecimalString(),
                'changeAmount' => Money::of((int) $payment->change_amount_minor, $currency)->toDecimalString(),
                'receivedAt' => $payment->received_at?->toIso8601String(),
                'methods' => $paymentMethodsUsed,
                'isSplit' => count($paymentMethodsUsed) > 1,
            ];
        })->all();

        $totalsFormatted = [];
        $breakdown = [];
        foreach ($totalsByMethodMinor as $method => $amountMinor) {
            $totalsFormatted[$method] = Money::of($amountMinor, $currency)->toDecimalString();
            $percentage = $totalGrossMinor > 0 ? round(($amountMinor / $totalGrossMinor) * 100, 1) : 0;

            $breakdown[] = [
                'method' => $method,
                'label' => self::methodLabel($method),
                'category' => $method === PaymentMethod::CASH->value ? 'cash' : 'digital',
                'amount' => Money::of($amountMinor, $currency)->toDecimalString(),
                'amountMinor' => $amountMinor,
                'count' => $countsByMethod[$method] ?? 0,
                'percentage' => $percentage,
            ];
        }

        $totalDigitalMinor = max(0, $totalGrossMinor - $totalCashMinor);

        return [
            'session' => [
                'id' => (string) $session->id,
                'sessionNumber' => (string) $session->session_number,
                'openedAt' => $session->opened_at?->toIso8601String(),
                'cashierName' => User::query()->find($session->cashier_user_id)?->name ?? 'Unknown',
                'openingFloat' => $session->openingFloat()->toDecimalString(),
            ],
            'summary' => [
                'totalGross' => Money::of($totalGrossMinor, $currency)->toDecimalString(),
                'totalCash' => Money::of($totalCashMinor, $currency)->toDecimalString(),
                'totalDigital' => Money::of($totalDigitalMinor, $currency)->toDecimalString(),
                'totalTransactions' => count($payments),
                'uniquePatientsCount' => $patientIds->count(),
                'receiptsCount' => $payments->filter(fn ($p) => $p->receipt !== null)->count(),
            ],
            'transactions' => $transactions,
            'totalsByMethod' => $totalsFormatted,
            'totalsByMethodBreakdown' => $breakdown,
            'totalGross' => Money::of($totalGrossMinor, $currency)->toDecimalString(),
            'currencyCode' => $currency,
        ];
    }

    public static function methodLabel(string $method): string
    {
        return match ($method) {
            PaymentMethod::CASH->value => 'Cash (Fedha Taslimu)',
            PaymentMethod::MOBILE_MONEY->value => 'Mobile Money (Lipa Namba)',
            PaymentMethod::BANK_TRANSFER->value => 'Bank Transfer (SimBanking)',
            PaymentMethod::GEPG->value => 'Control Number (GePG)',
            PaymentMethod::CARD->value => 'Card / POS',
            PaymentMethod::INSURANCE_SETTLEMENT->value => 'Insurance Settlement',
            default => ucwords(str_replace('_', ' ', $method)),
        };
    }
}
