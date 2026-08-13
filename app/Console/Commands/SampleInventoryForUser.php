<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventoryItemsUseCase;
use App\Modules\InventoryProcurement\Application\Services\DepartmentRequisitionScopeResolver;

class SampleInventoryForUser extends Command
{
    protected $signature = 'inventory:sample-items-for-user {userId} {--limit=10}';

    protected $description = 'Fetch a sample of inventory items visible to the given user to verify department scoping.';

    public function handle(): int
    {
        $userId = $this->argument('userId');
        $limit = (int) $this->option('limit');

        $user = User::find($userId);
        if (! $user) {
            $this->error('User not found');
            return 1;
        }

        $resolver = app(DepartmentRequisitionScopeResolver::class);
        $context = $resolver->contextForUser($user);
        $requestingDepartmentId = $context['lockedDepartment']['id'] ?? null;

        $this->line('User: '.$user->id.' - '.($user->name ?? ''));
        $this->line('Requesting Department ID: '.($requestingDepartmentId ?? '[none]'));

        $useCase = app(ListInventoryItemsUseCase::class);
        $items = $useCase->execute(['requestingDepartmentId' => $requestingDepartmentId, 'perPage' => $limit]);

        foreach ($items['data'] as $row) {
            $this->line(sprintf('%s | %s | %s', $row['itemCode'] ?? '-', $row['itemName'] ?? '-', $row['category'] ?? '-'));
        }

        return 0;
    }
}
