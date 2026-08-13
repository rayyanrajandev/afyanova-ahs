<?php

namespace App\Support\Audit;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class WebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toWebhook')) {
            return;
        }

        $webhookUrl = config('inventory_retention.sod_alerting.webhook_url');
        if (!$webhookUrl) {
            return;
        }

        $payload = $notification->toWebhook($notifiable);

        Http::timeout(5)
            ->retry(2, 100)
            ->post($webhookUrl, $payload);
    }
}
