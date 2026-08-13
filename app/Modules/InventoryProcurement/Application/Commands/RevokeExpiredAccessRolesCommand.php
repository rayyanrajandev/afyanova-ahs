<?php

namespace App\Modules\InventoryProcurement\Application\Commands;

use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1: Department-Level RBAC Implementation
 * Revoke inventory access roles that have reached their effective_until date
 *
 * Runs hourly to automatically expire temporary access grants
 * Complies with security best practices and regulatory requirements
 */
class RevokeExpiredAccessRolesCommand extends Command
{
    protected $signature = 'inventory:revoke-expired-access-roles';

    protected $description = 'Revoke inventory access roles that have reached their expiry date';

    public function handle(): int
    {
        $this->info('Starting inventory access role expiry check...');

        try {
            $now = now();

            // Find all roles with access_level (inventory roles) that have expired
            $expiredRoles = RoleModel::query()
                ->whereNotNull('access_level')
                ->whereNotNull('effective_until')
                ->where('effective_until', '<=', $now)
                ->whereNull('revoked_at')
                ->get();

            $revokedCount = 0;
            foreach ($expiredRoles as $role) {
                DB::transaction(function () use ($role, $now, &$revokedCount) {
                    $role->update([
                        'revoked_at' => $now,
                        'revocation_reason' => 'Automatic expiry: effective_until date reached',
                    ]);

                    $revokedCount++;

                    $this->line(sprintf(
                        '✓ Revoked role: %s (ID: %s) for department: %s',
                        $role->name,
                        $role->id,
                        $role->department?->name ?? 'N/A'
                    ));
                });
            }

            $this->info(sprintf(
                'Inventory access role expiry check completed: %d role(s) revoked',
                $revokedCount
            ));

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to revoke expired roles: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
