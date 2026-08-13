<?php

namespace App\Support\Audit;

use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalWorkflowInstanceModel;
use App\Notifications\SodViolationNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SodAlertNotifier
{
    /**
     * Send SOD violation alerts to relevant parties
     *
     * Notifies compliance officers and facility admins when a
     * segregation of duties violation is detected and recorded.
     */
    public function notifyViolation(
        User $approver,
        InventoryApprovalWorkflowInstanceModel $instance,
        string $violationReason
    ): void {
        $notification = new SodViolationNotification($approver, $instance, $violationReason);

        $recipients = $this->resolveRecipients($instance);

        if (empty($recipients)) {
            Log::warning('SOD violation detected but no notification recipients configured', [
                'workflow_instance_id' => $instance->id,
                'requisition_id' => $instance->requisition_id,
                'violation_reason' => $violationReason,
            ]);
            return;
        }

        Notification::send($recipients, $notification);

        Log::info('SOD violation notification sent', [
            'workflow_instance_id' => $instance->id,
            'recipient_count' => count($recipients),
            'violation_reason' => $violationReason,
        ]);
    }

    /**
     * Resolve notification recipients based on configuration
     *
     * Supports: comma-separated email list in config, or automatic
     * resolution of facility admins / compliance officers.
     *
     * @return array<int, User>
     */
    private function resolveRecipients(InventoryApprovalWorkflowInstanceModel $instance): array
    {
        $recipients = [];

        // 1. Check for configured notification emails
        $notificationEmails = config('inventory_retention.sod_alerting.notification_emails', []);
        foreach ($notificationEmails as $email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $recipients[] = $user;
            }
        }

        // 2. If no email config, try to find facility users with compliance/admin roles
        if (empty($recipients)) {
            $facilityUsers = User::where('tenant_id', $instance->tenant_id)
                ->whereHas('roles', fn ($q) =>
                    $q->whereIn('code', [
                        'FACILITY.COMPLIANCE.OFFICER',
                        'FACILITY.ADMIN',
                        'FACILITY.SUPER.ADMIN',
                    ])
                )
                ->limit(5)
                ->get();

            foreach ($facilityUsers as $user) {
                $recipients[] = $user;
            }
        }

        return $recipients;
    }
}
