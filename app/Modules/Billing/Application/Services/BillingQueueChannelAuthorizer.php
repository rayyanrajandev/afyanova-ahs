<?php

namespace App\Modules\Billing\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The billing-queue.{facilityId} channel's authorization logic, extracted
 * out of routes/channels.php the same way PatientFlowBoardChannelAuthorizer
 * is — directly unit testable, since the test suite forces
 * BROADCAST_CONNECTION=null (phpunit.xml), which means a closure inline in
 * routes/channels.php would never actually run in tests.
 *
 * Mirrors the same permission this module already gates /billing and
 * /billing-invoices on (billing.invoices.read — see
 * config/facilityPageEntitlements.ts and lib/routeAccess.ts on the frontend,
 * and the `can:billing.invoices.read` route middleware), plus a direct
 * facility_user pivot check rather than depending on the platform.scope
 * request-attribute having been resolved by the time /broadcasting/auth is
 * hit.
 */
class BillingQueueChannelAuthorizer
{
    public function authorize(User $user, string $facilityId): bool
    {
        if (! $user->can('billing.invoices.read')) {
            return false;
        }

        // Platform-only: a facility-scoped admin must still hold an active
        // facility_user membership to subscribe to another facility's
        // real-time billing queue — see RBAC_Remediation_Plan.md Phase 2.
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
