<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Modules\InventoryProcurement\Application\Services\DepartmentRequisitionScopeResolver;

class DiagnoseDepartmentContext extends Command
{
    protected $signature = 'inventory:diagnose-dept-context {names*}';

    protected $description = 'Diagnose department requisition context for given user name tokens';

    public function handle(): int
    {
        $names = $this->argument('names');
        $resolver = app(DepartmentRequisitionScopeResolver::class);

        foreach ($names as $name) {
            $this->info("Looking up users matching: {$name}");
            $users = User::query()->where('name', 'like', "%{$name}%")->get();
            if ($users->isEmpty()) {
                $this->line('  No users found');
                continue;
            }

            foreach ($users as $user) {
                $this->line('---');
                $this->line('User: '.$user->id.' - '.($user->name ?? '')); 
                $staffDept = optional($user->staffProfile)->department ?? null;
                $this->line('Staff profile department: '.($staffDept ?? '[none]'));
                $this->line('isFacilitySuperAdminAccess: '.($user->isFacilitySuperAdminAccess() ? 'yes' : 'no'));
                $context = $resolver->contextForUser($user);
                $this->line('Context:');
                $this->line('  canSelectAnyDepartment: '.(($context['canSelectAnyDepartment'] ?? false) ? 'true' : 'false'));
                $locked = $context['lockedDepartment'] ?? null;
                $this->line('  lockedDepartment: '.($locked ? ($locked['name'].' ('.$locked['id'].')') : '[null]'));
                $this->line('  staffDepartmentName: '.($context['staffDepartmentName'] ?? '[null]'));
            }
        }

        return 0;
    }
}
