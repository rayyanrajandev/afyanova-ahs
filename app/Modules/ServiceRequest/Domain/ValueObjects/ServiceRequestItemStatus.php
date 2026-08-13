<?php

namespace App\Modules\ServiceRequest\Domain\ValueObjects;

enum ServiceRequestItemStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case ORDERED = 'ordered';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
