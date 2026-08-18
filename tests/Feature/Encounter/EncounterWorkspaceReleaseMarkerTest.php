<?php

/**
 * The clinician workspace must be able to tell a written report from a released
 * one.
 *
 * DiagnosticOrdersTab derives its stage from `verifiedAt`: present means the
 * result is on the chart, absent means a report exists that the doctor cannot
 * read yet. If the workspace payload dropped that field, every completed order
 * would read "awaiting release" forever and "Send for Diagnostics" would never
 * disappear — a worse failure than the one it replaced. This pins the field to
 * the response contract.
 */

use App\Modules\Encounter\Application\UseCases\GetEncounterWorkspaceUseCase;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Encounter\Presentation\Http\Transformers\EncounterWorkspaceResponseTransformer;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function releaseMarkerPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTRM'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Release',
        'last_name' => 'Marker',
        'gender' => 'female',
        'date_of_birth' => '1990-01-01',
        'phone' => '+255700000031',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function releaseMarkerEncounter(string $patientId): EncounterModel
{
    return EncounterModel::query()->create([
        'encounter_number' => 'ENCRM'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'status' => 'opened',
        'type' => 'outpatient',
        'opened_at' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function releaseMarkerWorkspace(string $encounterId): array
{
    return EncounterWorkspaceResponseTransformer::transform(
        app(GetEncounterWorkspaceUseCase::class)->execute($encounterId)
    );
}

it('carries verifiedAt for laboratory orders through to the clinician payload', function (): void {
    $patient = releaseMarkerPatient();
    $encounter = releaseMarkerEncounter($patient->id);

    $released = LaboratoryOrderModel::query()->create([
        'order_number' => 'LABRM'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'ordered_at' => now()->subHour(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'status' => 'completed',
        'entry_state' => 'active',
        'result_summary' => 'Within range.',
        'verified_at' => now()->subMinutes(5),
    ]);

    $draft = LaboratoryOrderModel::query()->create([
        'order_number' => 'LABRM'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'ordered_at' => now()->subHour(),
        'test_code' => 'LOINC:718-7',
        'test_name' => 'Haemoglobin',
        'priority' => 'routine',
        'status' => 'completed',
        'entry_state' => 'active',
        'result_summary' => 'Typed but not signed off.',
        'verified_at' => null,
    ]);

    $byId = collect(releaseMarkerWorkspace($encounter->id)['laboratoryOrders'])->keyBy('id');

    // Both are `completed`; only the release marker separates them.
    expect($byId[$released->id]['status'])->toBe('completed');
    expect($byId[$draft->id]['status'])->toBe('completed');

    expect($byId[$released->id])->toHaveKey('verifiedAt');
    expect($byId[$released->id]['verifiedAt'])->not->toBeNull();
    expect($byId[$draft->id]['verifiedAt'])->toBeNull();
});

it('carries verifiedAt for radiology orders through to the clinician payload', function (): void {
    $patient = releaseMarkerPatient();
    $encounter = releaseMarkerEncounter($patient->id);

    $released = RadiologyOrderModel::query()->create([
        'order_number' => 'RADRM'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'ordered_at' => now()->subHour(),
        'modality' => 'xray',
        'study_description' => 'Chest X-Ray (PA)',
        'status' => 'completed',
        'entry_state' => 'active',
        'report_summary' => 'No acute findings.',
        'verified_at' => now()->subMinutes(5),
    ]);

    $draft = RadiologyOrderModel::query()->create([
        'order_number' => 'RADRM'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'ordered_at' => now()->subHour(),
        'modality' => 'ultrasound',
        'study_description' => 'Abdominal Ultrasound',
        'status' => 'completed',
        'entry_state' => 'active',
        'report_summary' => 'Typed but not signed off.',
        'verified_at' => null,
    ]);

    $byId = collect(releaseMarkerWorkspace($encounter->id)['radiologyOrders'])->keyBy('id');

    expect($byId[$released->id])->toHaveKey('verifiedAt');
    expect($byId[$released->id]['verifiedAt'])->not->toBeNull();
    expect($byId[$draft->id]['verifiedAt'])->toBeNull();
});
