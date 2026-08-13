<?php

namespace App\Modules\Appointment\Application\Exceptions;

use RuntimeException;

/**
 * Blocks booking a new appointment/walk-in for a patient who currently has
 * an active Emergency case or Admission — the cross-module duplicate-
 * booking gap flagged during the Reception/Emergency/Admission/
 * Bed-Management audit (deferred as "P7" until now). $conflictType
 * distinguishes which record conflicted so the controller can pick the
 * right transformer and context key; the two conflicts aren't merged into
 * separate exception classes since the check, catch, and response shape
 * are otherwise identical (same pattern as ActiveAppointmentConflictException).
 */
class PatientActiveEncounterConflictException extends RuntimeException
{
    /**
     * @param 'emergency_case'|'admission' $conflictType
     * @param array<string, mixed> $existingRecord
     */
    public function __construct(
        private readonly string $conflictType,
        private readonly array $existingRecord,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function conflictType(): string
    {
        return $this->conflictType;
    }

    /**
     * @return array<string, mixed>
     */
    public function existingRecord(): array
    {
        return $this->existingRecord;
    }
}
