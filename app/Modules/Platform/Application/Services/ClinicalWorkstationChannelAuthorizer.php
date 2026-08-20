<?php

namespace App\Modules\Platform\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Authorizes access to departmental clinical workstation WebSocket channels:
 * - laboratory-queue.{facilityId}
 * - radiology-queue.{facilityId}
 * - pharmacy-queue.{facilityId}
 * - procedure-queue.{facilityId}
 *
 * Checks that the user holds the corresponding read permission for the module
 * and has active membership in the requested facility.
 */
class ClinicalWorkstationChannelAuthorizer
{
    public function authorize(User $user, string $facilityId, string $requiredPermission): bool
    {
        if (! $user->can($requiredPermission)) {
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
