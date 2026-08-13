<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Application\Support\PosSaleAdjustmentSupport;
use App\Modules\Pos\Domain\Repositories\PosRegisterSessionRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosSaleAdjustmentRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosSaleRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosRegisterSessionStatus;
use App\Modules\Pos\Domain\ValueObjects\PosSaleAdjustmentType;
use App\Modules\Pos\Domain\ValueObjects\PosSaleStatus;
use Illuminate\Support\Facades\DB;

class VoidPosSaleUseCase
{
    public function __construct(
        private readonly PosSaleRepositoryInterface $posSaleRepository,
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

            $sessionId = (string) ($sale['pos_register_session_id'] ?? '');
            $session = $this->posRegisterSessionRepository->findById($sessionId, true);
            if ($session === null || ($session['status'] ?? null) !== PosRegisterSessionStatus::OPEN->value) {
                throw new PosOperationException(
                    'Sales can only be voided while their original cashier session is still open.',
                    'saleId',
                );
            }

            $adjustmentNumber = $this->posSaleAdjustmentSupport->generateAdjustmentNumber();
            $paymentMix = $this->posSaleAdjustmentSupport->summarizeSalePaymentMix($sale);
            $stockMovements = $this->posSaleAdjustmentSupport->restockPharmacyLineItems(
                sale: $sale,
                reason: 'pos_sale_void_restock',
                notePrefix: 'Restocked after POS sale void',
                adjustmentNumber: $adjustmentNumber,
                actorId: $actorId,
            );

            $metadata = array_merge(
                is_array($sale['metadata'] ?? null) ? $sale['metadata'] : [],
                [
                    'lastAdjustment' => [
                        'type' => PosSaleAdjustmentType::VOID->value,
                        'adjustmentNumber' => $adjustmentNumber,
                        'reasonCode' => $payload['reason_code'],
                        'processedAt' => now()->toIso8601String(),
                    ],
                ],
            );

            $this->posSaleRepository->update($id, [
                'status' => PosSaleStatus::VOIDED->value,
                'metadata' => $metadata,
            ]);

            $this->posSaleAdjustmentRepository->create([
                'tenant_id' => $sale['tenant_id'] ?? null,
                'facility_id' => $sale['facility_id'] ?? null,
                'pos_sale_id' => $sale['id'],
                'pos_register_id' => $sale['pos_register_id'],
                'pos_register_session_id' => $sale['pos_register_session_id'],
                'adjustment_number' => $adjustmentNumber,
                'adjustment_type' => PosSaleAdjustmentType::VOID->value,
                'amount' => round((float) ($sale['total_amount'] ?? 0), 2),
                'cash_amount' => $paymentMix['cashAmount'],
                'non_cash_amount' => $paymentMix['nonCashAmount'],
                'currency_code' => $sale['currency_code'] ?? 'TZS',
                'payment_method' => null,
                'adjustment_reference' => null,
                'reason_code' => $payload['reason_code'],
                'notes' => $payload['notes'] ?? null,
                'processed_by_user_id' => $actorId,
                'processed_at' => now(),
                'metadata' => array_merge(
                    is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                    [
                        'source' => 'pos.sale_void',
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
                'Only completed sales can be voided.',
                $field,
            );
        }
    }
}
