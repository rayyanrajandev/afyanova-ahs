<?php

namespace App\Modules\ServiceRequest\Application\Services;

use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;

/**
 * The running note on a visit, kept on the arrival event.
 *
 * Four operations on one thing, so this is a service rather than four use
 * cases: every one of them begins by resolving the same latest arrival event,
 * and splitting them apart would have produced four copies of that lookup —
 * which is exactly the duplication this refactor exists to remove.
 *
 * The append format (`[HH:MM Author]: text`) is defined here once. It was
 * previously written out twice — once for a nurse adding a note, once for the
 * return-to-reception hand-back — and the two were free to drift.
 */
class VisitNoteLogService
{
    public function notesFor(string $appointmentId): ?string
    {
        return $this->latestArrivalEvent($appointmentId)?->verification_notes;
    }

    /**
     * Append one authored line. Returns the resulting log, or null when the
     * visit has no arrival event to write against.
     */
    public function append(string $appointmentId, string $note, string $author): ?string
    {
        $arrivalEvent = $this->latestArrivalEvent($appointmentId);

        if ($arrivalEvent === null) {
            return null;
        }

        $existing = trim((string) $arrivalEvent->verification_notes);
        $line = sprintf('[%s %s]: %s', now()->format('H:i'), $author, trim($note));

        $arrivalEvent->update([
            'verification_notes' => $existing !== '' ? $existing."\n".$line : $line,
        ]);

        return $arrivalEvent->verification_notes;
    }

    /** Replace the whole log — the nurse editing it as free text. */
    public function replace(string $appointmentId, ?string $notes): ?string
    {
        $arrivalEvent = $this->latestArrivalEvent($appointmentId);

        if ($arrivalEvent === null) {
            return null;
        }

        $arrivalEvent->update(['verification_notes' => $notes]);

        return $arrivalEvent->verification_notes;
    }

    /** Remove one line by its position in the log. */
    public function deleteLine(string $appointmentId, int $index): ?string
    {
        $arrivalEvent = $this->latestArrivalEvent($appointmentId);

        if ($arrivalEvent === null || ! $arrivalEvent->verification_notes) {
            return $arrivalEvent?->verification_notes;
        }

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", (string) $arrivalEvent->verification_notes)),
            'strlen',
        ));

        if (! isset($lines[$index])) {
            return $arrivalEvent->verification_notes;
        }

        unset($lines[$index]);
        $updated = implode("\n", array_values($lines));

        $arrivalEvent->update(['verification_notes' => $updated !== '' ? $updated : null]);

        return $arrivalEvent->verification_notes;
    }

    public function latestArrivalEvent(string $appointmentId): ?ArrivalEventModel
    {
        return ArrivalEventModel::query()
            ->where('appointment_id', $appointmentId)
            ->orderByDesc('arrived_at')
            ->first();
    }
}
