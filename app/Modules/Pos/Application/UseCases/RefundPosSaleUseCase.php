<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Application\Support\PosSaleAdjustmentSupport;
use App\Modules\Pos\Domain\Repositories\PosRegisterRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosRegisterSessionRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosSaleAdjustmentRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosSaleRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosRegisterStatus;
use App\Modules\Pos\Domain\ValueObjects\PosSaleAdjustmentType;
use App\Modules\Pos\Domain\ValueObjects\PosSalePaymentMethod;
use App\Modules\Pos\Domain\ValueObjects\PosSaleStatus;
use Illuminate\Support\Facades\DB;

class RefundPosSaleUseCase
{
    public function __construct(
        private readonly PosSaleRepositoryInterface $posSaleRepository,
        private readonly PosRegisterRepositoryInterface $posRegisterRepository,
        private readonly PosRegisterSessionRepositoryInterface $posRegisterSessionRepository,
        private readonly PosSaleAdjustmentRepositoryInterface $posSaleAdjustmentRepository,
        private readonly PosSaleAdjustmentSupport $posSaleAdjustmentSupport,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($id, $payload, $actorId): ?array {
            $sale = $this->posSaleRepository->findById($id);
            if ($sale === null) {
                return null;
            }

            $this->ensureSaleCanBeAdjusted($sale, 'saleId');

            $registerId = trim((string) ($payload['pos_register_id'] ?? ''));
            $register = $this->posRegisterRepository->findById($registerId);
            if ($register === null) {
                throw new PosOperationException(
                    'Select an active register before processing a refund.',
                    'registerId',
                );
            }

            if (($register['status'] ?? null) !== PosRegisterStatus::ACTIVE->value) {
                throw new PosOperationException(
                    'Refund register must be active before a refund can be processed.',
                    'registerId',
                );
            }

            $session = $this->posRegisterSessionRepository->findOpenByRegisterId($registerId, true);
            if ($session === null) {
                throw new PosOperationException(
                    'Open a register session before processing a POS refund.',
                    'registerId',
                );
            }

            $adjustmentNumber = $this->posSaleAdjustmentSupport->generateAdjustmentNumber();
            $refundMethod = $this->resolveRefundMethod($payload['payment_method'] ?? null);
            $refundAmount = round((float) ($sale['total_amount'] ?? 0), 2);
            $stockMovements = $this->posSaleAdjustmentSupport->restockPharmacyLineItems(
                sale: $sale,
                reason: 'pos_sale_refund_restock',
                notePrefix: 'Restocked after POS sale refund',
                adjustmentNumber: $adjustmentNumber,
                actorId: $actorId,
            );

            $metadata = array_merge(
                is_array($sale['metadata'] ?? null) ? $sale['metadata'] : [],
                [
                    'lastAdjustment' => [
                        'type' => PosSaleAdjustmentType::REFUND->value,
                        'adjustmentNumber' => $adjustmentNumber,
                        'reasonCode' => $payload['reason_code'],
                        'processedAt' => now()->toIso8601String(),
                        'refundRegisterId' => $registerId,
                    ],
                ],
            );

            $this->posSaleRepository->update($id, [
                'status' => PosSaleStatus::REFUNDED->value,
                'metadata' => $metadata,
            ]);

            $isCashRefund = $refundMethod === PosSalePaymentMethod::CASH->value;

            $this->posSaleAdjustmentRepository->create([
                'tenant_id' => $sale['tenant_id'] ?? null,
                'facility_id' => $sale['facility_id'] ?? null,
                'pos_sale_id' => $sale['id'],
                'pos_register_id' => $registerId,
                'pos_register_session_id' => $session['id'],
                'adjustment_number' => $adjustmentNumber,
                'adjustment_type' => PosSaleAdjustmentType::REFUND->value,
                'amount' => $refundAmount,
                'cash_amount' => $isCashRefund ? $refundAmount : 0,
                'non_cash_amount' => $isCashRefund ? 0 : $refundAmount,
                'currency_code' => $sale['currency_code'] ?? 'TZS',
                'payment_method' => $refundMethod,
                'adjustment_reference' => $payload['adjustment_reference'] ?? null,
                'reason_code' => $payload['reason_code'],
                'notes' => $payload['notes'] ?? null,
                'processed_by_user_id' => $actorId,
                'processed_at' => now(),
                'metadata' => array_merge(
                    is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                    [
                        'source' => 'pos.sale_refund',
                        'originalSaleSessionId' => $sale['pos_register_session_id'] ?? null,
                        'stockMovementIds' => array_values(array_filter(array_map(
                            static fn (array $movement): ?string => $movement['id'] ?? null,
                            $stockMovements,
                        ))),
                    ],
                ),
            ]);

            return $this->posSaleRepository->findById($id);
        });
    }

    private function ensureSaleCanBeAdjusted(array $sale, string $field): void
    {
        $status = $sale['status'] ?? null;

        if ($status !== PosSaleStatus::COMPLETED->value) {
            throw new PosOperationException(
                'Only completed sales can be refunded.',
                $field,
            );
        }
    }

    private function resolveRefundMethod(mixed $value): string
    {
        $method = strtolower(trim((string) $value));

        if (! in_array($method, PosSalePaymentMethod::values(), true)) {
            throw new PosOperationException('Unsupported refund method.', 'refundMethod');
        }

        return $method;
    }
}
