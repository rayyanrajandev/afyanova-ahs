<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Domain\Repositories\PosRegisterRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosRegisterSessionRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosRegisterSessionStatus;
use App\Modules\Pos\Domain\ValueObjects\PosRegisterStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OpenPosRegisterSessionUseCase
{
    public function __construct(
        private readonly PosRegisterRepositoryInterface $posRegisterRepository,
        private readonly PosRegisterSessionRepositoryInterface $posRegisterSessionRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $registerId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($registerId, $payload, $actorId): ?array {
            $register = $this->posRegisterRepository->findById($registerId);
            if ($register === null) {
                return null;
            }

            if (($register['status'] ?? null) !== PosRegisterStatus::ACTIVE->value) {
                throw new PosOperationException(
                    'Register must be active before a cashier session can be opened.',
                    'registerId',
                );
            }

            if ($this->posRegisterSessionRepository->findOpenByRegisterId($registerId, true) !== null) {
                throw new PosOperationException(
                    'This register already has an open session.',
                    'registerId',
                );
            }

            $openingCashAmount = round(max((float) ($payload['opening_cash_amount'] ?? 0), 0), 2);

            return $this->posRegisterSessionRepository->create([
                'tenant_id' => $register['tenant_id'] ?? $this->platformScopeContext->tenantId(),
                'facility_id' => $register['facility_id'] ?? $this->platformScopeContext->facilityId(),
                'pos_register_id' => $registerId,
                'session_number' => $this->generateSessionNumber(),
                'status' => PosRegisterSessionStatus::OPEN->value,
                'opened_at' => now(),
                'closed_at' => null,
                'opening_cash_amount' => $openingCashAmount,
                'closing_cash_amount' => null,
                'expected_cash_amount' => $openingCashAmount,
                'discrepancy_amount' => null,
                'gross_sales_amount' => 0,
                'total_discount_amount' => 0,
                'total_tax_amount' => 0,
                'cash_net_sales_amount' => 0,
                'non_cash_sales_amount' => 0,
                'sale_count' => 0,
                'opening_note' => $this->nullableTrimmedValue($payload['opening_note'] ?? null),
                'closing_note' => null,
                'opened_by_user_id' => $actorId,
                'closed_by_user_id' => null,
            ]);
        });
    }

    private function generateSessionNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'PSESS'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->posRegisterSessionRepository->existsBySessionNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique POS session number.');
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
