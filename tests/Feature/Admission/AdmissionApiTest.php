<?php

use App\Models\User;
use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Modules\Admission\Infrastructure\Models\AdmissionAuditLogModel;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedAdmissionWardBedRegistry('Ward A', 'B-07');
    seedAdmissionWardBedRegistry('Ward C', 'C-11');
});

function makeAdmissionPatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Amina',
        'middle_name' => null,
        'last_name' => 'Moshi',
        'gender' => 'female',
        'date_of_birth' => '1996-04-21',
        'phone' => '+255700000001',
        'email' => null,
        'national_id' => null,
        'country_code' => 'TZ',
        'region' => null,
        'district' => null,
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function makeLinkedAppointment(string $patientId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Admission referral',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);
}

function admissionPayload(string $patientId, array $overrides = []): array
{
    return array_merge([
        'patientId' => $patientId,
        'admittedAt' => now()->toDateTimeString(),
        'ward' => 'Ward A',
        'bed' => 'B-07',
        'admissionReason' => 'Observation',
        'notes' => 'Needs monitoring',
    ], $overrides);
}

function grantAdmissionReadPermission(User $user): void
{
    $user->givePermissionTo('admissions.read');
}

function grantAdmissionCreatePermission(User $user): void
{
    $user->givePermissionTo('admissions.create');
}

function grantAdmissionUpdatePermission(User $user): void
{
    $user->givePermissionTo('admissions.update');
}

function grantAdmissionStatusUpdatePermission(User $user): void
{
    $user->givePermissionTo('admissions.update-status');
}

function seedAdmissionWardBedRegistry(
    string $wardName,
    string $bedNumber,
    ?string $tenantId = null,
    ?string $facilityId = null,
    string $status = 'active',
): FacilityResourceModel {
    return FacilityResourceModel::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'resource_type' => 'ward_bed',
        'code' => strtoupper('WB-'.preg_replace('/[^A-Za-z0-9]+/', '-', $wardName).'-'.$bedNumber),
        'name' => sprintf('%s %s', $wardName, $bedNumber),
        'department_id' => null,
        'service_point_type' => null,
        'ward_name' => $wardName,
        'bed_number' => $bedNumber,
        'location' => 'Admission registry',
        'status' => $status,
        'status_reason' => null,
        'notes' => 'Seeded for admission tests',
    ]);
}

function makeAdmissionReadUser(): User
{
    $user = User::factory()->create();
    grantAdmissionReadPermission($user);
    grantAdmissionCreatePermission($user);
    grantAdmissionUpdatePermission($user);
    grantAdmissionStatusUpdatePermission($user);

    return $user;
}

it('requires authentication for admission creation', function (): void {
    $patient = makeAdmissionPatient();

    $this->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->assertUnauthorized();
});

it('can create admission for active patient', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();
    $appointment = makeLinkedAppointment($patient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id, [
            'appointmentId' => $appointment->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.appointmentId', $appointment->id)
        ->assertJsonPath('data.status', 'admitted');
});

it('forbids admission creation without create permission', function (): void {
    $user = User::factory()->create();
    grantAdmissionReadPermission($user);
    $patient = makeAdmissionPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->assertForbidden();
});

it('rejects admission for inactive patient', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient(['status' => 'inactive']);

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['patientId']);
});

it('rejects admission when appointment does not belong to patient', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();
    $otherPatient = makeAdmissionPatient([
        'phone' => '+255799888777',
        'first_name' => 'Other',
        'last_name' => 'Patient',
    ]);
    $appointment = makeLinkedAppointment($otherPatient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id, [
            'appointmentId' => $appointment->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['appointmentId']);
});

it('fetches admission by id', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/admissions/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.id', $created['id']);
});

it('forbids admission show without read permission', function (): void {
    $writer = User::factory()->create();
    grantAdmissionCreatePermission($writer);
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($writer)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $userWithoutRead = User::factory()->create();

    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/admissions/'.$created['id'])
        ->assertForbidden();
});

it('updates admission fields', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'], [
            'ward' => 'Ward C',
            'bed' => 'C-11',
            'notes' => 'Transferred internally',
        ])
        ->assertOk()
        ->assertJsonPath('data.ward', 'Ward C')
        ->assertJsonPath('data.bed', 'C-11');
});

it('forbids admission update without update permission', function (): void {
    $creator = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $user = User::factory()->create();
    grantAdmissionReadPermission($user);
    grantAdmissionCreatePermission($user);

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'], [
            'notes' => 'Unauthorized update attempt',
        ])
        ->assertForbidden();
});

it('rejects admission creation when ward and bed do not match an active registry placement', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id, [
            'ward' => 'Ward Missing',
            'bed' => 'M-01',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ward', 'bed']);
});

it('rejects admission updates when resulting placement is not in the active ward-bed registry', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'], [
            'ward' => 'Ward Missing',
            'bed' => 'M-01',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ward', 'bed']);
});

it('rejects admission creation when selected placement is already occupied by another active admission', function (): void {
    $user = makeAdmissionReadUser();
    $occupyingPatient = makeAdmissionPatient();
    $incomingPatient = makeAdmissionPatient([
        'phone' => '+255700000111',
        'first_name' => 'Rehema',
        'last_name' => 'Kileo',
    ]);

    AdmissionModel::query()->create([
        'admission_number' => 'ADM20260321OCCUPY1',
        'patient_id' => $occupyingPatient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward A',
        'bed' => 'B-07',
        'admitted_at' => now()->subHours(3)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Transferred placement still active',
        'notes' => null,
        'status' => 'transferred',
        'status_reason' => 'Moved from OPD handoff',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($incomingPatient->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ward', 'bed']);
});

it('rejects admission updates when resulting placement is already occupied by another active admission', function (): void {
    $user = makeAdmissionReadUser();
    $sourcePatient = makeAdmissionPatient();
    $occupyingPatient = makeAdmissionPatient([
        'phone' => '+255700000112',
        'first_name' => 'Occupying',
        'last_name' => 'Patient',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($sourcePatient->id))
        ->json('data');

    AdmissionModel::query()->create([
        'admission_number' => 'ADM20260321OCCUPY2',
        'patient_id' => $occupyingPatient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward C',
        'bed' => 'C-11',
        'admitted_at' => now()->subHours(2)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Occupied destination bed',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'], [
            'ward' => 'Ward C',
            'bed' => 'C-11',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ward', 'bed']);
});

it('rejects transferred status when destination placement is already occupied by another active admission', function (): void {
    $user = makeAdmissionReadUser();
    $sourcePatient = makeAdmissionPatient();
    $occupyingPatient = makeAdmissionPatient([
        'phone' => '+255700000113',
        'first_name' => 'Blocking',
        'last_name' => 'Occupant',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($sourcePatient->id))
        ->json('data');

    AdmissionModel::query()->create([
        'admission_number' => 'ADM20260321OCCUPY3',
        'patient_id' => $occupyingPatient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward C',
        'bed' => 'C-11',
        'admitted_at' => now()->subHours(1)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Blocks transfer destination',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'].'/status', [
            'status' => 'transferred',
            'reason' => 'Moved to higher care',
            'receivingWard' => 'Ward C',
            'receivingBed' => 'C-11',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['receivingWard', 'receivingBed']);
});
it('rejects status lifecycle fields on admission detail update endpoint', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'], [
            'ward' => 'Should Not Persist',
            'status' => 'cancelled',
            'reason' => 'Lifecycle update attempt',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status', 'reason']);

    $admission = AdmissionModel::query()->findOrFail($created['id']);
    expect($admission->ward)->toBe('Ward A');
    expect($admission->status)->toBe('admitted');
});

it('updates admission status and sets discharged timestamp', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'].'/status', [
            'status' => 'discharged',
            'reason' => 'Recovered',
            'dischargeDestination' => 'Home',
            'followUpPlan' => 'Return to medical clinic after 7 days for review.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'discharged')
        ->assertJsonPath('data.statusReason', 'Recovered')
        ->assertJsonPath('data.dischargeDestination', 'Home')
        ->assertJsonPath('data.followUpPlan', 'Return to medical clinic after 7 days for review.');

    $record = AdmissionModel::query()->find($created['id']);
    expect($record?->discharged_at)->not->toBeNull();
    expect($record?->discharge_destination)->toBe('Home');
    expect($record?->follow_up_plan)->toBe('Return to medical clinic after 7 days for review.');
});

it('forbids admission status update without status permission', function (): void {
    $creator = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $user = User::factory()->create();
    grantAdmissionReadPermission($user);
    grantAdmissionCreatePermission($user);
    grantAdmissionUpdatePermission($user);

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'].'/status', [
            'status' => 'discharged',
            'reason' => 'Unauthorized discharge attempt',
            'dischargeDestination' => 'Home',
        ])
        ->assertForbidden();
});

it('requires discharge destination when discharging patient', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'].'/status', [
            'status' => 'discharged',
            'reason' => 'Recovered',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['dischargeDestination']);
});

it('lists common discharge destination options for admissions', function (): void {
    $user = makeAdmissionReadUser();

    $this->actingAs($user)
        ->getJson('/api/v1/admissions/discharge-destination-options')
        ->assertOk()
        ->assertJsonPath('data.0.value', 'Home / self-care')
        ->assertJsonPath('data.0.group', 'Community discharge');
});

it('requires destination placement for transferred status and writes placement metadata', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'].'/status', [
            'status' => 'transferred',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason', 'receivingWard', 'receivingBed']);

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'].'/status', [
            'status' => 'transferred',
            'reason' => 'Moved to higher care',
            'receivingWard' => 'Ward C',
            'receivingBed' => 'C-11',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'transferred')
        ->assertJsonPath('data.statusReason', 'Moved to higher care')
        ->assertJsonPath('data.ward', 'Ward C')
        ->assertJsonPath('data.bed', 'C-11');

    $statusLog = AdmissionAuditLogModel::query()
        ->where('admission_id', $created['id'])
        ->where('action', 'admission.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('admitted');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('transferred');
    expect($statusLog?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['reason_provided'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['receiving_placement']['ward'] ?? null)->toBe('Ward C');
    expect($statusLog?->metadata['receiving_placement']['bed'] ?? null)->toBe('C-11');
    expect($statusLog?->changes['ward']['before'] ?? null)->toBe('Ward A');
    expect($statusLog?->changes['ward']['after'] ?? null)->toBe('Ward C');
    expect($statusLog?->changes['bed']['before'] ?? null)->toBe('B-07');
    expect($statusLog?->changes['bed']['after'] ?? null)->toBe('C-11');
});

it('rejects transferred status when destination placement is not in the active ward-bed registry', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'].'/status', [
            'status' => 'transferred',
            'reason' => 'Moved to higher care',
            'receivingWard' => 'Ward Missing',
            'receivingBed' => 'M-01',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['receivingWard', 'receivingBed']);
});
it('writes admission audit logs for create update and status change', function (): void {
    $user = makeAdmissionReadUser();
    $user->givePermissionTo('admissions.view-audit-logs');
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/admissions/'.$created['id'], [
        'ward' => 'Ward C',
        'bed' => 'C-11',
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/admissions/'.$created['id'].'/status', [
        'status' => 'cancelled',
        'reason' => 'admission withdrawn',
    ])->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/admissions/'.$created['id'].'/audit-logs?perPage=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('data.0.action', 'admission.status.updated')
        ->assertJsonPath('data.1.action', 'admission.updated');
});

it('forbids admission audit log access without permission', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/admissions/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('forbids admission audit logs when gate override denies', function (): void {
    Gate::define('admissions.view-audit-logs', static fn (): bool => false);

    $user = makeAdmissionReadUser();
    $user->givePermissionTo('admissions.view-audit-logs');
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/admissions/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('returns 404 for admission audit logs of unknown id', function (): void {
    $user = makeAdmissionReadUser();
    $user->givePermissionTo('admissions.view-audit-logs');

    $this->actingAs($user)
        ->getJson('/api/v1/admissions/5ca5e8f2-0d38-45f3-b290-8f1ff0eaf57b/audit-logs')
        ->assertNotFound();
});

it('lists and filters admissions', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    AdmissionModel::query()->create([
        'admission_number' => 'ADM20260225AAAAAA',
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward A',
        'bed' => 'A-01',
        'admitted_at' => now()->subHours(4)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Observation',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    AdmissionModel::query()->create([
        'admission_number' => 'ADM20260225BBBBBB',
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward B',
        'bed' => 'B-01',
        'admitted_at' => now()->subHours(10)->toDateTimeString(),
        'discharged_at' => now()->subHours(2)->toDateTimeString(),
        'admission_reason' => 'Procedure',
        'notes' => null,
        'status' => 'discharged',
        'status_reason' => 'Done',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/admissions?status=admitted&ward=Ward A')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.ward', 'Ward A')
        ->assertJsonPath('data.0.status', 'admitted');
});

/**
 * Postgres's LIKE is case-sensitive (unlike SQLite, which this test suite
 * runs on) — a plain where('admission_reason', 'like', ...) silently
 * missed mixed-case matches in production. Fixed via
 * EloquentAdmissionRepository::applyCaseInsensitiveSearch(). Can't
 * reproduce the case-sensitivity bug itself on SQLite, but this documents
 * and guards the intended case-insensitive behavior going forward.
 */
it('finds admissions by search term regardless of case', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    AdmissionModel::query()->create([
        'admission_number' => 'ADM20260225CCCCCC',
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Cardiology Ward',
        'bed' => 'C-01',
        'admitted_at' => now()->subHours(1)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Chest pain observation',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    foreach (['cardiology', 'CARDIOLOGY', 'CaRdIoLoGy'] as $term) {
        $this->actingAs($user)
            ->getJson('/api/v1/admissions?q='.$term)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ward', 'Cardiology Ward');
    }
});

it('forbids admission list without read permission', function (): void {
    $userWithoutRead = User::factory()->create();
    $patient = makeAdmissionPatient();

    AdmissionModel::query()->create([
        'admission_number' => 'ADM20260225READ00',
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward Access',
        'bed' => 'R-01',
        'admitted_at' => now()->subHours(2)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Permission check',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/admissions')
        ->assertForbidden();
});

it('stamps admission tenant and facility scope when created under resolved platform scope', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    [$tenantId, $facilityId] = seedAdmissionPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-ADM',
        facilityName: 'Dar Inpatient',
    );

    $created = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZH',
            'X-Facility-Code' => 'DAR-ADM',
        ])
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $row = AdmissionModel::query()->findOrFail($created['id']);

    expect($row->tenant_id)->toBe($tenantId);
    expect($row->facility_id)->toBe($facilityId);
});

it('filters admission reads by facility scope when platform multi facility scoping is enabled', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', true);

    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    [$tenantId, $facilityId] = seedAdmissionPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-ADM',
        facilityName: 'Nairobi Ward',
    );

    [, $otherFacilityId] = seedAdmissionPlatformScopeFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-ADM',
        facilityName: 'Mombasa Ward',
    );

    $visible = AdmissionModel::query()->create([
        'admission_number' => 'ADM20260225SCOPA1',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward Scope A',
        'bed' => 'A-10',
        'admitted_at' => now()->subHour()->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Visible admission',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    $hidden = AdmissionModel::query()->create([
        'admission_number' => 'ADM20260225SCOPA2',
        'tenant_id' => $tenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward Scope B',
        'bed' => 'B-10',
        'admitted_at' => now()->subHours(2)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Hidden admission',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-ADM',
        ])
        ->getJson('/api/v1/admissions')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.ward', 'Ward Scope A');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-ADM',
        ])
        ->getJson('/api/v1/admissions/'.$hidden->id)
        ->assertNotFound();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-ADM',
        ])
        ->patchJson('/api/v1/admissions/'.$hidden->id, [
            'ward' => 'Attempted cross-facility transfer',
        ])
        ->assertNotFound();
});

it('filters admission reads by facility scope when enabled via feature flag override', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', false);

    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    [$tenantId, $facilityId] = seedAdmissionPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-ADM',
        facilityName: 'Nairobi Ward',
    );

    [, $otherFacilityId] = seedAdmissionPlatformScopeFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-ADM',
        facilityName: 'Mombasa Ward',
    );

    DB::table('feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_name' => 'platform.multi_facility_scoping',
        'scope_type' => 'country',
        'scope_key' => 'KE',
        'enabled' => true,
        'reason' => 'enable scoping for Kenya admission rollout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $visible = AdmissionModel::query()->create([
        'admission_number' => 'ADM20260225SCOPA3',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Override Ward A',
        'bed' => 'A-11',
        'admitted_at' => now()->subHour()->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Override visible admission',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    AdmissionModel::query()->create([
        'admission_number' => 'ADM20260225SCOPA4',
        'tenant_id' => $tenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Override Ward B',
        'bed' => 'B-11',
        'admitted_at' => now()->subHours(2)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Override hidden admission',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-ADM',
        ])
        ->getJson('/api/v1/admissions')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.ward', 'Override Ward A');
});

it('blocks admission creation in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks admission update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $admission = AdmissionModel::query()->create([
        'admission_number' => 'ADM20260225GUARDA1',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Guard Ward A',
        'bed' => 'G-01',
        'admitted_at' => now()->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Guard update target',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$admission->id, [
            'ward' => 'Attempted guarded update',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks admission status update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $admission = AdmissionModel::query()->create([
        'admission_number' => 'ADM20260225GUARDA2',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Guard Ward B',
        'bed' => 'G-02',
        'admitted_at' => now()->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Guard status target',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$admission->id.'/status', [
            'status' => 'transferred',
            'reason' => 'Attempted guarded transfer',
            'receivingWard' => 'Guard Ward C',
            'receivingBed' => 'G-03',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('creates an admission linked to a real bed resource and returns it in the response', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();
    $bed = seedAdmissionWardBedRegistry('Ward D', 'D-01');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id, [
            'ward' => null,
            'bed' => null,
            'bedResourceId' => $bed->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.bedResourceId', $bed->id)
        ->assertJsonPath('data.bedResource.id', $bed->id)
        ->assertJsonPath('data.bedResource.wardName', 'Ward D')
        ->assertJsonPath('data.bedResource.bedNumber', 'D-01')
        // ward/bed are derived FROM the resource, never trusted from client input.
        ->assertJsonPath('data.ward', 'Ward D')
        ->assertJsonPath('data.bed', 'D-01');

    $record = AdmissionModel::query()->findOrFail($response->json('data.id'));
    expect($record->bed_resource_id)->toBe($bed->id);
});

it('rejects admission creation when the bed resource is not active', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();
    $bed = seedAdmissionWardBedRegistry('Ward Inactive', 'I-01', status: 'inactive');

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id, [
            'ward' => null,
            'bed' => null,
            'bedResourceId' => $bed->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bedResourceId']);
});

it('rejects admission creation when the bed resource is already occupied by another active admission', function (): void {
    $user = makeAdmissionReadUser();
    $occupyingPatient = makeAdmissionPatient();
    $incomingPatient = makeAdmissionPatient([
        'phone' => '+255700000211',
        'first_name' => 'Bed',
        'last_name' => 'Conflict',
    ]);
    $bed = seedAdmissionWardBedRegistry('Ward E', 'E-01');

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($occupyingPatient->id, [
            'ward' => null,
            'bed' => null,
            'bedResourceId' => $bed->id,
        ]))
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($incomingPatient->id, [
            'ward' => null,
            'bed' => null,
            'bedResourceId' => $bed->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bedResourceId']);
});

it('transfers an admission to a real bed resource and clears the previous string placement', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();
    $destinationBed = seedAdmissionWardBedRegistry('Ward F', 'F-01');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id))
        ->json('data');

    expect($created['bedResourceId'])->toBeNull();

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'].'/status', [
            'status' => 'transferred',
            'reason' => 'Moved to a tracked bed',
            'receivingBedResourceId' => $destinationBed->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'transferred')
        ->assertJsonPath('data.bedResourceId', $destinationBed->id)
        ->assertJsonPath('data.ward', 'Ward F')
        ->assertJsonPath('data.bed', 'F-01');

    expect($response->json('data.bedResourceId'))->toBe($destinationBed->id);
});

it('lists available beds with server-computed occupancy', function (): void {
    $user = makeAdmissionReadUser();
    $occupyingPatient = makeAdmissionPatient();
    $occupiedBed = seedAdmissionWardBedRegistry('Ward G', 'G-01');

    $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($occupyingPatient->id, [
            'ward' => null,
            'bed' => null,
            'bedResourceId' => $occupiedBed->id,
        ]))
        ->assertCreated()
        ->json('data');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/admissions/available-beds')
        ->assertOk();

    $beds = collect($response->json('data'));
    $occupiedRow = $beds->firstWhere('id', $occupiedBed->id);
    $freeRow = $beds->firstWhere('wardName', 'Ward A');

    expect($occupiedRow)->not->toBeNull();
    expect($occupiedRow['isOccupied'])->toBeTrue();
    expect($occupiedRow['occupiedByAdmissionNumber'])->not->toBeNull();
    // No patient name exposed — the bed picker's job is showing what's free.
    expect($occupiedRow)->not->toHaveKey('occupiedByPatientName');

    expect($freeRow)->not->toBeNull();
    expect($freeRow['isOccupied'])->toBeFalse();
    expect($freeRow['occupiedByAdmissionId'])->toBeNull();
});

it('forbids available beds without read permission', function (): void {
    $userWithoutRead = User::factory()->create();

    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/admissions/available-beds')
        ->assertForbidden();
});

it('counts admissions discharged within a given range separately from admitted-at scoped counts', function (): void {
    $user = makeAdmissionReadUser();
    $patient = makeAdmissionPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/admissions', admissionPayload($patient->id, [
            'admittedAt' => now()->subDays(5)->toDateTimeString(),
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/admissions/'.$created['id'].'/status', [
            'status' => 'discharged',
            'reason' => 'Recovered',
            'dischargeDestination' => 'Home',
        ])
        ->assertOk();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/admissions/status-counts?dischargedFrom='.now()->startOfDay()->toDateTimeString().'&dischargedTo='.now()->endOfDay()->toDateTimeString())
        ->assertOk();

    // Admitted 5 days ago, so it wouldn't show as "admitted today" — but it
    // WAS discharged today, and dischargedInRange must reflect that.
    expect($response->json('data.dischargedInRange'))->toBe(1);
});

/**
 * @return array{0:string,1:string}
 */
function seedAdmissionPlatformScopeAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedAdmissionPlatformScopeFacility(
        tenantCode: $tenantCode,
        tenantName: $tenantName,
        countryCode: $countryCode,
        facilityCode: $facilityCode,
        facilityName: $facilityName,
    );

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'admission_clerk',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}

/**
 * @return array{0:string,1:string}
 */
function seedAdmissionPlatformScopeFacility(
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    $tenant = DB::table('tenants')->where('code', $tenantCode)->first();

    if ($tenant === null) {
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'code' => $tenantCode,
            'name' => $tenantName,
            'country_code' => $countryCode,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        $tenantId = (string) $tenant->id;
    }

    $facilityId = (string) Str::uuid();
    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => $facilityCode,
        'name' => $facilityName,
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Nairobi',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}
