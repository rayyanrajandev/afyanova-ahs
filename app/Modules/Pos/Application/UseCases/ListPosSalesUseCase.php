<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Pos\Domain\Repositories\PosSaleRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosSaleChannel;
use App\Modules\Pos\Domain\ValueObjects\PosSalePaymentMethod;
use App\Modules\Pos\Domain\ValueObjects\PosSaleStatus;
use Carbon\CarbonImmutable;

class ListPosSalesUseCase
{
    public function __construct(private readonly PosSaleRepositoryInterface $posSaleRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $registerId = isset($filters['registerId']) ? trim((string) $filters['registerId']) : null;
        $registerId = $registerId === '' ? null : $registerId;

        $sessionId = isset($filters['sessionId']) ? trim((string) $filters['sessionId']) : null;
        $sessionId = $sessionId === '' ? null : $sessionId;

        $paymentMethod = isset($filters['paymentMethod']) ? trim((string) $filters['paymentMethod']) : null;
        if (! in_array($paymentMethod, PosSalePaymentMethod::values(), true)) {
            $paymentMethod = null;
        }

        $saleChannel = isset($filters['saleChannel']) ? trim((string) $filters['saleChannel']) : null;
        if (! in_array($saleChannel, PosSaleChannel::values(), true)) {
            $saleChannel = null;
        }

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, PosSaleStatus::values(), true)) {
            $status = null;
        }

        $soldFrom = $this->normalizeDateBoundary($filters['soldFrom'] ?? null, true);
        $soldTo = $this->normalizeDateBoundary($filters['soldTo'] ?? null, false);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->posSaleRepository->search(
            query: $query,
            registerId: $registerId,
            sessionId: $sessionId,
            paymentMethod: $paymentMethod,
            saleChannel: $saleChannel,
            status: $status,
            soldFrom: $soldFrom,
            soldTo: $soldTo,
            page: $page,
            perPage: $perPage,
        );
    }

    private function normalizeDateBoundary(mixed $value, bool $startOfDay): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($normalized);
        } catch (\Throwable) {
            return null;
        }

        return ($startOfDay ? $date->startOfDay() : $date->endOfDay())->toDateTimeString();
    }
}
