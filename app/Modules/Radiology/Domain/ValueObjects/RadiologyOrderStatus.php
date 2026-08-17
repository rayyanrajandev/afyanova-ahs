<?php

namespace App\Modules\Radiology\Domain\ValueObjects;

enum RadiologyOrderStatus: string
{
    case ORDERED = 'ordered';
    case SCHEDULED = 'scheduled';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * @return array<int, string>
     */
    public static function openWorklistValues(): array
    {
        return [
            self::ORDERED->value,
            self::SCHEDULED->value,
            self::IN_PROGRESS->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function terminalValues(): array
    {
        return [self::COMPLETED->value, self::CANCELLED->value];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedForwardTransitions(string $currentStatus): array
    {
        return match ($currentStatus) {
            // Two paths out of `ordered`, deliberately — this is the difference
            // between an imaging department and a booking system.
            //
            //  - **Unscheduled / walk-in** (`ordered -> in_progress`): the common
            //    case for plain film and ultrasound. A doctor orders a chest
            //    X-ray mid-consultation and the patient walks to imaging with the
            //    request, exactly as they would to the lab. Forcing a booking
            //    first meant inventing an appointment for something happening in
            //    the next five minutes.
            //
            //  - **Scheduled** (`ordered -> scheduled -> in_progress`): for work
            //    that genuinely needs a slot — CT and MRI, studies needing
            //    preparation or contrast, or anything booked for a later day.
            //
            // Both paths are standard, not a local shortcut: IHE's Radiology
            // Scheduled Workflow profile defines an explicit *Unscheduled Case*
            // in which the modality performs a study with no prior scheduled
            // procedure step, and DICOM keeps MPPS (performed) separate from MWL
            // (scheduled) for precisely this reason. FHIR agrees —
            // ServiceRequest.occurrence is optional.
            self::ORDERED->value => [self::SCHEDULED->value, self::IN_PROGRESS->value, self::CANCELLED->value],
            self::SCHEDULED->value => [self::IN_PROGRESS->value, self::CANCELLED->value],
            self::IN_PROGRESS->value => [self::COMPLETED->value, self::CANCELLED->value],
            self::COMPLETED->value, self::CANCELLED->value => [],
            default => [],
        };
    }

    public static function canTransitionForward(string $currentStatus, string $nextStatus): bool
    {
        return in_array($nextStatus, self::allowedForwardTransitions($currentStatus), true);
    }
}
