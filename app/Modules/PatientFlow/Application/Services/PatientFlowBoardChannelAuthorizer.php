<?php

namespace App\Modules\PatientFlow\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The patient-flow.{facilityId} channel's authorization logic, extracted
 * out of routes/channels.php into its own class so it's directly unit
 * testable — the test suite forces BROADCAST_CONNECTION=null
 * (phpunit.xml), which means the real /broadcasting/auth HTTP endpoint
 * never actually invokes a Broadcast::channel() closure in tests (the null
 * broadcaster no-ops), so a closure inline in routes/channels.php would be
 * untestable without overriding the broadcaster driver mid-test.
 *
 * Mirrors the same permission check `can:appointments.read` middleware
 * already applies everywhere else on this board (Gate::before falls back to
 * $user->hasPermissionTo(), see AppServiceProvider::boot()), plus a direct
 * facility_user pivot check rather than depending on the platform.scope
 * request-attribute having been resolved by the time /broadcasting/auth is
 * hit.
 */
class PatientFlowBoardChannelAuthorizer
{
    public function authorize(User $user, string $facilityId): bool
    {
        if (! $user->can('appointments.read')) {
            return false;
        }

        // Platform-only: a facility-scoped admin must still hold an active
        // facility_user membership to subscribe to another facility's
        // real-time board — see RBAC_Remediation_Plan.md Phase 2.
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
