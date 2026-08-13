<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Pos\Domain\Repositories\PosRegisterRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosRegisterStatus;

class ListPosRegistersUseCase
{
    public function __construct(private readonly PosRegisterRepositoryInterface $posRegisterRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, PosRegisterStatus::values(), true)) {
            $status = null;
        }

        $sortMap = [
            'registerCode' => 'register_code',
            'registerName' => 'register_name',
            'location' => 'location',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'registerName'] ?? 'register_name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->posRegisterRepository->search(
            query: $query,
            status: $status,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
