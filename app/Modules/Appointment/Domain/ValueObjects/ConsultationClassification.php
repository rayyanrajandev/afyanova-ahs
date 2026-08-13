<?php

namespace App\Modules\Appointment\Domain\ValueObjects;

enum ConsultationClassification: string
{
    case NEW = 'new';
    case REVIEW = 'review';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && in_array($value, self::values(), true);
    }

    public static function fromString(?string $value): self
    {
        return match (strtolower(trim((string) $value))) {
            'review' => self::REVIEW,
            default  => self::NEW,
        };
    }
}
