<?php

namespace Tests\Feature\Nursing;

use App\Models\User;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Domain\ValueObjects\EncounterStatus;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;
use App\Modules\ServiceRequest\Infrastructure\Models\ServiceRequestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Shared fixtures for the nursing suite, mirroring RevenueTestSupport.
 *
 * A class of static methods rather than Pest helper functions: helpers declared
 * in a test file are global, so two files declaring `visitWithArrival()` is a
 * fatal redeclare and one file relying on another's helper breaks the moment
 * anyone runs a single file with --filter. Both of those were hit while writing
 * this suite.
 */
class NursingTestSupport
{
    public static function nurse(): User
    {
        $roles = (array) config('roles');

        return makeUserWithRole((array) $roles['nurse-officer']['permissions'], 'CLINICAL.NURSE');
    }

    public static function patient(string $first = 'Halima', string $last = 'Juma'): string
    {
        $patientId = (string) Str::uuid();

        DB::table('patients')->insert([
            'id' => $patientId,
            'patient_number' => 'PT-'.Str::upper(Str::random(8)),
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'female',
            'date_of_birth' => '1991-09-03',
            'country_code' => 'TZ',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $patientId;
    }

    /**
     * A visit with an encounter, and optionally an appointment and arrival event.
     *
     * @param  array{encounterStatus?: string, appointmentStatus?: string, withAppointment?: bool,
     *               arrivalNotes?: string|null, withArrival?: bool, encounterType?: string,
     *               admissionId?: string|null}  $options
     * @return array{patientId: string, appointmentId: string|null, encounterId: string}
     */
    public static function visit(array $options = []): array
    {
        $patientId = self::patient();
        $withAppointment = $options['withAppointment'] ?? true;

        $appointment = null;
        if ($withAppointment) {
            $appointment = AppointmentModel::query()->create([
                'appointment_number' => 'APT-'.Str::upper(Str::random(8)),
                'patient_id' => $patientId,
                'department' => 'General',
                'scheduled_at' => now(),
                'status' => $options['appointmentStatus'] ?? AppointmentStatus::WAITING_TRIAGE->value,
                'consultation_type' => 'new',
                'financial_coverage_type' => 'self_pay',
            ]);

            if (($options['withArrival'] ?? false) || array_key_exists('arrivalNotes', $options)) {
                ArrivalEventModel::query()->create([
                    'appointment_id' => $appointment->id,
                    'arrival_mode' => 'walk_in',
                    'arrived_at' => now(),
                    'verification_notes' => $options['arrivalNotes'] ?? null,
                ]);
            }
        }

        $encounter = EncounterModel::query()->create([
            'encounter_number' => 'ENC'.Str::upper(Str::random(8)),
            'patient_id' => $patientId,
            'appointment_id' => $appointment?->id,
            'admission_id' => $options['admissionId'] ?? null,
            'status' => $options['encounterStatus'] ?? EncounterStatus::OPENED->value,
            'type' => $options['encounterType'] ?? 'outpatient',
            'opened_at' => now(),
        ]);

        return [
            'patientId' => $patientId,
            'appointmentId' => $appointment === null ? null : (string) $appointment->id,
            'encounterId' => (string) $encounter->id,
        ];
    }

    /**
     * Mark a visit as assessed, which is what removes it from the worklist.
     */
    public static function assess(array $visit, int $assessedByUserId): ServiceRequestModel
    {
        return ServiceRequestModel::query()->create([
            'request_number' => 'SR-'.Str::upper(Str::random(8)),
            'patient_id' => $visit['patientId'],
            'appointment_id' => $visit['appointmentId'],
            'encounter_id' => $visit['encounterId'],
            'service_type' => 'nursing_assessment',
            'priority' => 'routine',
            'status' => 'completed',
            'requested_at' => now(),
            'assessed_by_user_id' => $assessedByUserId,
            'assessed_at' => now(),
        ]);
    }

    /**
     * @return array<int, string> Encounter ids currently on the nursing worklist.
     */
    public static function worklistIds(TestResponse $response): array
    {
        return collect($response->json('data'))->pluck('id')->all();
    }
}
