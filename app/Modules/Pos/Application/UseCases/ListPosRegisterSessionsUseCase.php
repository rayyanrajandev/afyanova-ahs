<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Pos\Domain\Repositories\PosRegisterSessionRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosRegisterSessionStatus;

class ListPosRegisterSessionsUseCase
{
    public function __construct(private readonly PosRegisterSessionRepositoryInterface $posRegisterSessionRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, PosRegisterSessionStatus::values(), true)) {
            $status = null;
        }

        $registerId = isset($filters['registerId']) ? trim((string) $filters['registerId']) : null;
        $registerId = $registerId === '' ? null : $registerId;

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->posRegisterSessionRepository->search(
            query: $query,
            registerId: $registerId,
            status: $status,
            page: $page,
            perPage: $perPage,
        );
    }
}
