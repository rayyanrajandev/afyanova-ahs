<?php

namespace App\Notifications;

use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalWorkflowInstanceModel;
use App\Support\Audit\WebhookChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SodViolationNotification extends Notification
{
    public function __construct(
        private readonly User $approver,
        private readonly InventoryApprovalWorkflowInstanceModel $instance,
        private readonly string $violationReason,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (config('inventory_retention.sod_alerting.webhook_enabled', false)) {
            $channels[] = WebhookChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $requisition = $this->instance->requisition;

        $message = (new MailMessage)
            ->subject('SOD Violation — ' . $requisition->requisition_number)
            ->greeting('Segregation of Duties Violation')
            ->line('A segregation of duties violation was detected during the approval process.')
            ->line('---')
            ->line("**Requisition:** {$requisition->requisition_number}")
            ->line("**Approver:** {$this->approver->name} ({$this->approver->email})")
            ->line("**Violation:** {$this->violationReason}")
            ->line("**Status:** Flagged and logged")
            ->line('---')
            ->line('This violation has been recorded in the audit log for compliance review.')
            ->action('View Requisition', url("/inventory-procurement/requisitions/{$requisition->id}"))
            ->line('This is an automated compliance notification.');

        return $message;
    }

    public function toWebhook(object $notifiable): array
    {
        $requisition = $this->instance->requisition;

        return [
            'event' => 'sod_violation',
            'severity' => 'warning',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'requisition_number' => $requisition->requisition_number,
                'requisition_id' => $requisition->id,
                'workflow_instance_id' => $this->instance->id,
                'approver_id' => $this->approver->id,
                'approver_name' => $this->approver->name,
                'approver_email' => $this->approver->email,
                'violation_reason' => $this->violationReason,
                'actor_department' => $this->approver->staffProfile?->department,
                'tenant_id' => $this->instance->tenant_id,
            ],
        ];
    }
}
