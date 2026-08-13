<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Domain\Repositories\PosRegisterRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosRegisterSessionRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosSaleRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosCustomerType;
use App\Modules\Pos\Domain\ValueObjects\PosRegisterStatus;
use App\Modules\Pos\Domain\ValueObjects\PosSaleChannel;
use App\Modules\Pos\Domain\ValueObjects\PosSaleLineType;
use App\Modules\Pos\Domain\ValueObjects\PosSalePaymentMethod;
use App\Modules\Pos\Domain\ValueObjects\PosSaleStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CreatePosSaleUseCase
{
    public function __construct(
        private readonly PosRegisterRepositoryInterface $posRegisterRepository,
        private readonly PosRegisterSessionRepositoryInterface $posRegisterSessionRepository,
        private readonly PosSaleRepositoryInterface $posSaleRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($payload, $actorId): ?array {
            $registerId = trim((string) ($payload['pos_register_id'] ?? ''));
            $register = $this->posRegisterRepository->findById($registerId);
            if ($register === null) {
                return null;
            }

            if (($register['status'] ?? null) !== PosRegisterStatus::ACTIVE->value) {
                throw new PosOperationException(
                    'Register must be active before a sale can be recorded.',
                    'registerId',
                );
            }

            $session = $this->posRegisterSessionRepository->findOpenByRegisterId($registerId, true);
            if ($session === null) {
                throw new PosOperationException(
                    'Open a register session before recording a sale.',
                    'registerId',
                );
            }

            $customerType = $this->resolveCustomerType($payload);
            $lineItemSummary = $this->normalizeLineItems($payload['line_items'] ?? []);
            $paymentSummary = $this->normalizePayments(
                payments: $payload['payments'] ?? [],
                saleTotal: $lineItemSummary['total_amount'],
                actorId: $actorId,
            );

            return $this->posSaleRepository->create(
                saleAttributes: [
                    'tenant_id' => $register['tenant_id'] ?? $this->platformScopeContext->tenantId(),
                    'facility_id' => $register['facility_id'] ?? $this->platformScopeContext->facilityId(),
                    'pos_register_id' => $registerId,
                    'pos_register_session_id' => (string) ($session['id'] ?? ''),
                    'patient_id' => $this->nullableTrimmedValue($payload['patient_id'] ?? null),
                    'sale_number' => $this->generateSaleNumber(),
                    'receipt_number' => $this->generateReceiptNumber(),
                    'sale_channel' => $this->resolveSaleChannel($payload['sale_channel'] ?? null),
                    'customer_type' => $customerType,
                    'customer_name' => $this->nullableTrimmedValue($payload['customer_name'] ?? null),
                    'customer_reference' => $this->nullableTrimmedValue($payload['customer_reference'] ?? null),
                    'currency_code' => $this->resolveCurrencyCode(
                        $payload['currency_code'] ?? ($register['default_currency_code'] ?? null),
                    ),
                    'status' => PosSaleStatus::COMPLETED->value,
                    'subtotal_amount' => $lineItemSummary['subtotal_amount'],
                    'discount_amount' => $lineItemSummary['discount_amount'],
                    'tax_amount' => $lineItemSummary['tax_amount'],
                    'total_amount' => $lineItemSummary['total_amount'],
                    'paid_amount' => $lineItemSummary['total_amount'],
                    'balance_amount' => 0,
                    'change_amount' => $paymentSummary['change_amount'],
                    'sold_at' => $payload['sold_at'] ?? now(),
                    'completed_by_user_id' => $actorId,
                    'notes' => $this->nullableTrimmedValue($payload['notes'] ?? null),
                    'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
                ],
                lineItems: $lineItemSummary['line_items'],
                payments: $paymentSummary['payments'],
            );
        });
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     * @return array{line_items: array<int, array<string, mixed>>, subtotal_amount: float, discount_amount: float, tax_amount: float, total_amount: float}
     */
    private function normalizeLineItems(array $lineItems): array
    {
        if ($lineItems === []) {
            throw new PosOperationException('At least one sale line is required.', 'lineItems');
        }

        $normalized = [];
        $subtotal = 0.0;
        $discountAmount = 0.0;
        $taxAmount = 0.0;
        $totalAmount = 0.0;

        foreach (array_values($lineItems) as $index => $lineItem) {
            $itemName = trim((string) ($lineItem['item_name'] ?? ''));
            if ($itemName === '') {
                throw new PosOperationException('Line item name is required.', "lineItems.$index.itemName");
            }

            $quantity = round(max((float) ($lineItem['quantity'] ?? 0), 0), 2);
            if ($quantity <= 0) {
                throw new PosOperationException('Line quantity must be greater than zero.', "lineItems.$index.quantity");
            }

            $unitPrice = round(max((float) ($lineItem['unit_price'] ?? 0), 0), 2);
            if ($unitPrice <= 0) {
                throw new PosOperationException('Line unit price must be greater than zero.', "lineItems.$index.unitPrice");
            }

            $lineSubtotalAmount = round($quantity * $unitPrice, 2);
            $lineDiscountAmount = round(max((float) ($lineItem['discount_amount'] ?? 0), 0), 2);
            $lineTaxAmount = round(max((float) ($lineItem['tax_amount'] ?? 0), 0), 2);

            if ($lineDiscountAmount > $lineSubtotalAmount) {
                throw new PosOperationException(
                    'Line discount cannot exceed the line subtotal.',
                    "lineItems.$index.discountAmount",
                );
            }

            $lineTotalAmount = round(($lineSubtotalAmount - $lineDiscountAmount) + $lineTaxAmount, 2);
            if ($lineTotalAmount <= 0) {
                throw new PosOperationException(
                    'Each sale line must contribute a positive total.',
                    "lineItems.$index",
                );
            }

            $normalized[] = [
                'line_number' => $index + 1,
                'item_type' => $this->resolveLineType($lineItem['item_type'] ?? null),
                'item_reference' => $this->nullableTrimmedValue($lineItem['item_reference'] ?? null),
                'item_code' => $this->nullableTrimmedValue($lineItem['item_code'] ?? null),
                'item_name' => $itemName,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_subtotal_amount' => $lineSubtotalAmount,
                'discount_amount' => $lineDiscountAmount,
                'tax_amount' => $lineTaxAmount,
                'line_total_amount' => $lineTotalAmount,
                'notes' => $this->nullableTrimmedValue($lineItem['notes'] ?? null),
                'metadata' => is_array($lineItem['metadata'] ?? null) ? $lineItem['metadata'] : null,
            ];

            $subtotal += $lineSubtotalAmount;
            $discountAmount += $lineDiscountAmount;
            $taxAmount += $lineTaxAmount;
            $totalAmount += $lineTotalAmount;
        }

        return [
            'line_items' => $normalized,
            'subtotal_amount' => round($subtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => round($totalAmount, 2),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $payments
     * @return array{payments: array<int, array<string, mixed>>, change_amount: float}
     */
    private function normalizePayments(array $payments, float $saleTotal, ?int $actorId): array
    {
        if ($payments === []) {
            throw new PosOperationException('At least one payment entry is required.', 'payments');
        }

        $remaining = round($saleTotal, 2);
        $changeAmount = 0.0;
        $normalized = [];

        foreach (array_values($payments) as $index => $payment) {
            if ($remaining <= 0) {
                throw new PosOperationException(
                    'Payments already cover the sale total.',
                    "payments.$index.amount",
                );
            }

            $paymentMethod = $this->resolvePaymentMethod($payment['payment_method'] ?? null);
            $amountReceived = round(max((float) ($payment['amount'] ?? 0), 0), 2);

            if ($amountReceived <= 0) {
                throw new PosOperationException(
                    'Payment amount must be greater than zero.',
                    "payments.$index.amount",
                );
            }

            if ($paymentMethod !== PosSalePaymentMethod::CASH->value && $amountReceived > $remaining) {
                throw new PosOperationException(
                    'Non-cash payments cannot exceed the remaining balance.',
                    "payments.$index.amount",
                );
            }

            $amountApplied = round(min($amountReceived, $remaining), 2);
            $changeGiven = $paymentMethod === PosSalePaymentMethod::CASH->value
                ? round(max($amountReceived - $remaining, 0), 2)
                : 0.0;

            if ($amountApplied <= 0) {
                throw new PosOperationException(
                    'Payment amount must settle part of the sale balance.',
                    "payments.$index.amount",
                );
            }

            $remaining = round(max($remaining - $amountApplied, 0), 2);
            $changeAmount += $changeGiven;

            $normalized[] = [
                'payment_method' => $paymentMethod,
                'amount_received' => $amountReceived,
                'amount_applied' => $amountApplied,
                'change_given' => $changeGiven,
                'payment_reference' => $this->nullableTrimmedValue($payment['payment_reference'] ?? null),
                'paid_at' => $payment['paid_at'] ?? now(),
                'collected_by_user_id' => $actorId,
                'note' => $this->nullableTrimmedValue($payment['note'] ?? null),
                'metadata' => is_array($payment['metadata'] ?? null) ? $payment['metadata'] : null,
            ];
        }

        if ($remaining > 0) {
            throw new PosOperationException('Payments do not cover the sale total.', 'payments');
        }

        return [
            'payments' => $normalized,
            'change_amount' => round($changeAmount, 2),
        ];
    }

    private function resolveCustomerType(array $payload): string
    {
        $patientId = $this->nullableTrimmedValue($payload['patient_id'] ?? null);
        $requestedType = $this->nullableTrimmedValue($payload['customer_type'] ?? null);

        if ($requestedType === null && $patientId !== null) {
            return PosCustomerType::PATIENT->value;
        }

        $customerType = $requestedType ?? PosCustomerType::ANONYMOUS->value;

        if ($customerType === PosCustomerType::PATIENT->value && $patientId === null) {
            throw new PosOperationException(
                'Patient linkage is required when the customer type is patient.',
                'patientId',
            );
        }

        if (! in_array($customerType, PosCustomerType::values(), true)) {
            return PosCustomerType::ANONYMOUS->value;
        }

        return $customerType;
    }

    private function resolveSaleChannel(mixed $value): string
    {
        $saleChannel = strtolower(trim((string) $value));

        return in_array($saleChannel, PosSaleChannel::values(), true)
            ? $saleChannel
            : PosSaleChannel::GENERAL_RETAIL->value;
    }

    private function resolveLineType(mixed $value): string
    {
        $lineType = strtolower(trim((string) $value));

        return in_array($lineType, PosSaleLineType::values(), true)
            ? $lineType
            : PosSaleLineType::MANUAL->value;
    }

    private function resolvePaymentMethod(mixed $value): string
    {
        $paymentMethod = strtolower(trim((string) $value));

        if (! in_array($paymentMethod, PosSalePaymentMethod::values(), true)) {
            throw new PosOperationException('Unsupported payment method.', 'payments');
        }

        return $paymentMethod;
    }

    private function resolveCurrencyCode(mixed $value): string
    {
        $currencyCode = strtoupper(trim((string) $value));

        return $currencyCode !== '' ? $currencyCode : $this->defaultCurrencyResolver->resolve();
    }

    private function generateSaleNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'POSS'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->posSaleRepository->existsBySaleNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique POS sale number.');
    }

    private function generateReceiptNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'PSR'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->posSaleRepository->existsByReceiptNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique POS receipt number.');
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
