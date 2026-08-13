<?php

namespace App\Modules\Notifications\Domain\Enums;

enum NotificationPriority: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case NORMAL = 'normal';
    case INFORMATIONAL = 'informational';
}
