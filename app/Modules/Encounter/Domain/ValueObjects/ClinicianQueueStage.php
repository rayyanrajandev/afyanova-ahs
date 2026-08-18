<?php

namespace App\Modules\Encounter\Domain\ValueObjects;

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which pile of the clinician's queue a visit belongs in.
 *
 * This lived in the browser, written twice — once to filter the list and once to
 * count the tabs — and both copies tested encounter statuses that do not exist.
 * `admitted`, `completed`, `resolved`, `in_consultation` and `open` are none of
 * them EncounterStatus values (the real set is opened / in_progress /
 * ready_for_sign / signed / closed / amended / cancelled), so every one of those
 * comparisons was dead. The visible cost was an encounter with no appointment:
 * its only route into a pile was `status === "open"`, which never matched, so a
 * walk-in was fetched from the server and then silently belonged nowhere.
 *
 * Defining it here means the list, the counts and the filter all read one rule,
 * and that rule can be pushed into SQL — so a queue is a real query rather than
 * whatever survived the first page.
 */
enum ClinicianQueueStage: string
{
    case WAITING_PROVIDER = 'waiting_provider';
    case IN_CONSULTATION = 'in_consultation';
    case ADMITTED = 'admitted';
    case COMPLETED = 'completed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $stage): string => $stage->value, self::cases());
    }

    public static function tryFromFilter(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * Encounter statuses that mean the visit is over.
     *
     * `cancelled` is grouped with `closed` deliberately: both are finished, and
     * a cancelled encounter left in "waiting" would sit at the top of a doctor's
     * list forever.
     */
    private const CLOSED_ENCOUNTER_STATUSES = [
        EncounterStatus::CLOSED->value,
        EncounterStatus::CANCELLED->value,
    ];

    /**
     * Appointment statuses that mean the visit is over.
     */
    private const CLOSED_APPOINTMENT_STATUSES = [
        AppointmentStatus::COMPLETED->value,
        AppointmentStatus::CANCELLED->value,
        AppointmentStatus::NO_SHOW->value,
    ];

    /**
     * Encounter statuses that still represent live work when there is no
     * appointment to read a stage from — a direct encounter opened at the desk.
     */
    private const LIVE_ENCOUNTER_STATUSES = [
        EncounterStatus::OPENED->value,
        EncounterStatus::IN_PROGRESS->value,
        EncounterStatus::READY_FOR_SIGN->value,
    ];

    /**
     * Narrow a query to one pile.
     *
     * The order of the exclusions is the order of precedence, and it matters:
     * an admitted patient whose appointment still reads `in_consultation` is
     * admitted, not in consultation.
     */
    public function applyTo(Builder $query): Builder
    {
        return match ($this) {
            self::ADMITTED => $query->whereNotNull('admission_id'),

            self::COMPLETED => $query
                ->whereNull('admission_id')
                ->where(function (Builder $inner): void {
                    $inner
                        ->whereIn('status', self::CLOSED_ENCOUNTER_STATUSES)
                        ->orWhereHas('appointment', function (Builder|QueryBuilder $appointment): void {
                            $appointment->whereIn('status', self::CLOSED_APPOINTMENT_STATUSES);
                        });
                }),

            self::IN_CONSULTATION => $query
                ->whereNull('admission_id')
                ->whereNotIn('status', self::CLOSED_ENCOUNTER_STATUSES)
                ->whereHas('appointment', function (Builder|QueryBuilder $appointment): void {
                    $appointment->where('status', AppointmentStatus::IN_CONSULTATION->value);
                }),

            self::WAITING_PROVIDER => $query
                ->whereNull('admission_id')
                ->whereNotIn('status', self::CLOSED_ENCOUNTER_STATUSES)
                ->where(function (Builder $inner): void {
                    $inner
                        ->whereHas('appointment', function (Builder|QueryBuilder $appointment): void {
                            // A visit already in the room, or already over, is
                            // not waiting — and it would otherwise qualify here,
                            // because `triaged_at` stays set for the rest of the
                            // visit once triage has happened.
                            $appointment
                                ->whereNotIn('status', array_merge(
                                    [AppointmentStatus::IN_CONSULTATION->value],
                                    self::CLOSED_APPOINTMENT_STATUSES,
                                ))
                                // Sent through from triage, or triaged at any point.
                                ->where(function (Builder|QueryBuilder $waiting): void {
                                    $waiting
                                        ->where('status', AppointmentStatus::WAITING_PROVIDER->value)
                                        ->orWhereNotNull('triaged_at');
                                });
                        })
                        // A direct encounter with no appointment behind it. This
                        // is the case the browser rule could never match, so it
                        // appeared in no pile at all.
                        ->orWhere(function (Builder $direct): void {
                            $direct
                                ->whereNull('appointment_id')
                                ->whereIn('status', self::LIVE_ENCOUNTER_STATUSES);
                        });
                }),
        };
    }
}
