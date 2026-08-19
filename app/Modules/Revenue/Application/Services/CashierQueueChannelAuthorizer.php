<?php

namespace App\Modules\Revenue\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may listen to cashier-queue.{facilityId}.
 *
 * Mirrors the ability the queue's own endpoint requires — a channel that told
 * you the queue had changed while the endpoint refused to show it would be a
 * pointless side door — plus an active facility membership, checked directly
 * against the pivot rather than relying on the platform scope having been
 * resolved by the time /broadcasting/auth is reached.
 *
 * Extracted from routes/channels.php so it is unit-testable: the suite forces
 * BROADCAST_CONNECTION=null, so a closure in that file is never invoked by a
 * test.
 */
class CashierQueueChannelAuthorizer
{
    public function authorize(User $user, string $facilityId): bool
    {
        if (! $user->can('cashier.charges.read')) {
            return false;
        }

        if ($user->isPlatformSuperAdminAccess()) {
            return true;
        }

        return DB::table('facility_user')
            ->where('user_id', $user->id)
            ->where('facility_id', $facilityId)
            ->where('is_active', true)
            ->exists();
    }
}
