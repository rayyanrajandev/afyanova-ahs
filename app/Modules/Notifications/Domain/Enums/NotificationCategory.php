<?php

namespace App\Modules\Notifications\Domain\Enums;

enum NotificationCategory: string
{
    case CLINICAL = 'clinical';
    case LABORATORY = 'laboratory';
    case PHARMACY = 'pharmacy';
    case BILLING = 'billing';
    case ADMINISTRATION = 'administration';
    case SYSTEM = 'system';
}
