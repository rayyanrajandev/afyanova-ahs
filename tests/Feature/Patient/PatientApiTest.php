<?php

use App\Models\User;
use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientAuditLogModel;
use App\Modules\Patient\Infrastructure\Models\PatientAllergyModel;
use App\Modules\Patient\Infrastructure\Models\PatientMedicationProfileModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureFacilitySubscriptionEntitlement::class);
});

function patientPayload(array $overrides = []): array
{
    return array_merge([
        'firstName' => 'Amina',
        'middleName' => null,
        'lastName' => 'Moshi',
        'gender' => 'female',
        'dateOfBirth' => '1996-04-21',
        'phone' => '+255700000001',
        'email' => 'amina@example.test',
        'nationalId' => 'TZ-123456789',
        'countryCode' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Ilala',
        'addressLine' => 'Msasani',
        'nextOfKinName' => 'Juma Moshi',
        'nextOfKinPhone' => '+255700000002',
    ], $overrides);
}

function grantPatientReadPermission(User $user): void
{
    $user->givePermissionTo('patients.read');
}

function grantPatientCreatePermission(User $user): void
{
    $user->givePermissionTo('patients.create');
}

/**
 * `patient.demographics.update`, not the old `patients.update` — bug
 * found and fixed 2026-08-11. `patients.update` was the broad
 * pre-refactor permission; database/migrations/2026_07_16_000002_
 * insert_workflow_permissions.php's own role-mapping documents it
 * being deliberately split into granular ones (`patient.demographics.
 * update`, `patient.allergies.manage`, `patient.medications.manage`,
 * `patient.vitals.record`) as a least-privilege improvement — routes/
 * api.php's PATCH patients/{id} route already checks the new name.
 * This helper granting the old one meant every "manage" test user in
 * this file was silently missing the permission the route actually
 * checks, 403ing on requests that were supposed to succeed. The fix is
 * here, not in the route: the app's check was already correct and more
 * secure than what this helper was testing against.
 */
function grantPatientUpdatePermission(User $user): void
{
    $user->givePermissionTo('patient.demographics.update');
}

function grantPatientStatusUpdatePermission(User $user): void
{
    $user->givePermissionTo('patients.update-status');
}

function makePatientReadUser(): User
{
    $user = User::factory()->create();
    grantPatientReadPermission($user);
    grantPatientCreatePermission($user);

    return $user;
}

function makePatientManageUser(): User
{
    $user = makePatientReadUser();
    grantPatientUpdatePermission($user);
    grantPatientStatusUpdatePermission($user);

    return $user;
}

/**
 * Bug found and fixed 2026-08-11: this was a bare alias to
 * makePatientManageUser(), never actually granting the two
 * medication-safety-specific permissions (routes/api.php:
 * `can:patient.allergies.manage` / `can:patient.medications.manage`
 * on the allergy and medication-profile store/update routes) its own
 * name promises. Every allergy/medication test using this helper was
 * 403ing on the exact operations it exists to test.
 */
function makePatientSafetyUser(): User
{
    $user = makePatientManageUser();
    $user->givePermissionTo('patient.allergies.manage');
    $user->givePermissionTo('patient.medications.manage');

    return $user;
}

it('requires authentication for patient registration', function (): void {
    $this->postJson('/api/v1/patients', patientPayload())
        ->assertUnauthorized();
});

it('can register a patient', function (): void {
    $user = makePatientReadUser();

    $response = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload());

    $response
        ->assertCreated()
        ->assertJsonPath('data.firstName', 'Amina')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('warnings', []);

    expect($response->json('data.patientNumber'))->toMatch('/^[0-9]{8}$/');
});

it('forbids patient registration without create permission', function (): void {
    $user = User::factory()->create();
    grantPatientReadPermission($user);

    $this->actingAs($user)
        ->postJson('/api/v1/patients', patientPayload())
        ->assertForbidden();
});

it('allows exact demographic duplicate registration with a warning when hard identifiers differ', function (): void {
    $user = makePatientReadUser();

    $first = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/patients', patientPayload([
            'email' => 'second@example.test',
            'nationalId' => 'TZ-222222222',
        ]))
        ->assertCreated()
        ->assertJsonPath('warnings.0.id', $first['id'])
        ->assertJsonPath('warnings.0.duplicateConfidenceLabel', 'strong');

    $this->actingAs($user)
        ->postJson('/api/v1/patients', patientPayload([
            'email' => 'second@example.test',
            'bypassDuplicateCheck' => true,
        ]))
        ->assertConflict()
        ->assertJsonPath('duplicates.0.id', $first['id']);

    expect(PatientModel::query()->count())->toBe(2);
});

it('allows duplicate registration when only a shared Tanzania phone matches', function (): void {
    $user = makePatientReadUser();

    $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/patients', patientPayload([
            'firstName' => 'Halima',
            'lastName' => 'Kweka',
            'dateOfBirth' => '1981-09-12',
            'gender' => 'female',
            'phone' => '0700000001',
            'email' => 'local-phone@example.test',
            'nationalId' => 'TZ-LOCAL-PHONE',
        ]))
        ->assertCreated()
        ->assertJsonPath('warnings', []);

    expect(PatientModel::query()->count())->toBe(2);
});

it('warns instead of blocking when a patient without NIDA matches demographics strongly', function (): void {
    $user = makePatientReadUser();

    $first = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload([
        'nationalId' => null,
    ]))->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/patients', patientPayload([
            'phone' => '+255755100200',
            'email' => 'no-nida-second@example.test',
            'nationalId' => null,
        ]))
        ->assertCreated()
        ->assertJsonPath('warnings.0.id', $first['id'])
        ->assertJsonPath('warnings.0.duplicateConfidenceLabel', 'strong');

    expect(PatientModel::query()->count())->toBe(2);
});

it('blocks duplicate registration by national id even when demographic fields differ', function (): void {
    $user = makePatientReadUser();

    $first = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/patients', patientPayload([
            'firstName' => 'Different',
            'lastName' => 'Person',
            'dateOfBirth' => '1988-02-14',
            'phone' => '+255755111222',
            'email' => 'same-national-id@example.test',
            'nationalId' => 'TZ123456789',
        ]))
        ->assertConflict()
        ->assertJsonPath('duplicates.0.id', $first['id']);
});

it('reports no duplicates for a check that matches nothing', function (): void {
    $user = makePatientReadUser();

    $this->actingAs($user)
        ->postJson('/api/v1/patients/duplicate-check', [
            'firstName' => 'Nobody',
            'lastName' => 'Matches',
        ])
        ->assertOk()
        ->assertJsonPath('data.severity', 'none')
        ->assertJsonPath('data.duplicates', []);

    expect(PatientModel::query()->count())->toBe(0);
});

it('reports a strong_warning duplicate check without creating anything', function (): void {
    $user = makePatientReadUser();

    $first = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/patients/duplicate-check', [
            'firstName' => 'Amina',
            'lastName' => 'Moshi',
            'gender' => 'female',
            'dateOfBirth' => '1996-04-21',
            'phone' => '+255700000009',
            'addressLine' => 'Msasani',
        ])
        ->assertOk()
        ->assertJsonPath('data.severity', 'strong_warning')
        ->assertJsonPath('data.duplicates.0.id', $first['id']);

    expect(PatientModel::query()->count())->toBe(1);
});

it('reports a hard_block duplicate check when the national id matches exactly', function (): void {
    $user = makePatientReadUser();

    $first = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/patients/duplicate-check', [
            'firstName' => 'Different',
            'lastName' => 'Person',
            'nationalId' => 'TZ-123456789',
        ])
        ->assertOk()
        ->assertJsonPath('data.severity', 'hard_block')
        ->assertJsonPath('data.duplicates.0.id', $first['id']);

    expect(PatientModel::query()->count())->toBe(1);
});

it('forbids the duplicate-check endpoint without create permission', function (): void {
    $user = User::factory()->create();
    grantPatientReadPermission($user);

    $this->actingAs($user)
        ->postJson('/api/v1/patients/duplicate-check', ['firstName' => 'Amina'])
        ->assertForbidden();
});

it('allows registration with matching demographics when phone and national id are different', function (): void {
    $user = makePatientReadUser();

    $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/patients', patientPayload([
            'phone' => '+255755111222',
            'email' => 'same-demographics-new-phone@example.test',
            'nationalId' => 'TZ-222222222',
        ]))
        ->assertCreated();

    expect(PatientModel::query()->count())->toBe(2);
});

it('rejects future date of birth', function (): void {
    $user = makePatientManageUser();

    $this->actingAs($user)
        ->postJson('/api/v1/patients', patientPayload([
            'dateOfBirth' => now()->addDay()->toDateString(),
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['dateOfBirth']);
});

it('requires phone during patient registration', function (): void {
    $user = makePatientReadUser();

    $this->actingAs($user)
        ->postJson('/api/v1/patients', patientPayload([
            'phone' => null,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone']);
});

it('requires patient origin fields during registration', function (): void {
    $user = makePatientManageUser();

    $this->actingAs($user)
        ->postJson('/api/v1/patients', patientPayload([
            'countryCode' => '',
            'region' => '',
            'district' => '',
            'addressLine' => '',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['countryCode', 'region', 'district', 'addressLine']);
});

it('fetches patient by id', function (): void {
    $user = makePatientReadUser();
    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.id', $created['id']);
});

it('forbids patient show without read permission', function (): void {
    $writer = User::factory()->create();
    grantPatientCreatePermission($writer);

    $created = $this->actingAs($writer)->postJson('/api/v1/patients', patientPayload())->json('data');
    $userWithoutRead = User::factory()->create();

    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/patients/'.$created['id'])
        ->assertForbidden();
});

it('forbids patient list without read permission', function (): void {
    $userWithoutRead = User::factory()->create();

    PatientModel::query()->create([
        'patient_number' => 'PT20260225READ00',
        'first_name' => 'Read',
        'middle_name' => null,
        'last_name' => 'Blocked',
        'gender' => 'female',
        'date_of_birth' => '1992-01-10',
        'phone' => '+255700000111',
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
    ]);

    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/patients')
        ->assertForbidden();
});

it('returns 404 for unknown patient id', function (): void {
    $user = makePatientReadUser();

    $this->actingAs($user)
        ->getJson('/api/v1/patients/37ba7f5b-b5eb-4ee1-a989-f904a590cb2b')
        ->assertNotFound();
});

it('returns a patient summary with identity, alerts, and zeroed active-order counts for a freshly registered patient', function (): void {
    $user = makePatientReadUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    PatientAllergyModel::query()->create([
        'patient_id' => $patient['id'],
        'tenant_id' => null,
        'substance_code' => 'ATC:N02BE01',
        'substance_name' => 'Paracetamol 500mg',
        'reaction' => 'Rash',
        'severity' => 'severe',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/summary')
        ->assertOk()
        ->assertJsonPath('data.patient.id', $patient['id'])
        ->assertJsonPath('data.patient.firstName', 'Amina')
        ->assertJsonPath('data.alerts.0.substanceName', 'Paracetamol 500mg')
        ->assertJsonPath('data.alerts.0.severity', 'severe')
        ->assertJsonPath('data.insurance', null)
        ->assertJsonPath('data.latestEncounter', null)
        ->assertJsonPath('data.workflowStatus', null)
        ->assertJsonPath('data.activeOrders.labActive', 0)
        ->assertJsonPath('data.activeOrders.pharmacyActive', 0)
        ->assertJsonPath('data.activeOrders.imagingActive', 0)
        ->assertJsonPath('data.activeOrders.procedureActive', 0);
});

it('caps summary alerts at 5, ordered by severity', function (): void {
    $user = makePatientReadUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    foreach (range(1, 6) as $index) {
        PatientAllergyModel::query()->create([
            'patient_id' => $patient['id'],
            'tenant_id' => null,
            'substance_code' => "ATC:{$index}",
            'substance_name' => "Substance {$index}",
            'reaction' => 'Rash',
            'severity' => 'mild',
            'status' => 'active',
        ]);
    }

    $response = $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/summary')
        ->assertOk();

    expect($response->json('data.alerts'))->toHaveCount(5);
});

it('returns 404 for the summary endpoint on an unknown patient id', function (): void {
    $user = makePatientReadUser();

    $this->actingAs($user)
        ->getJson('/api/v1/patients/37ba7f5b-b5eb-4ee1-a989-f904a590cb2b/summary')
        ->assertNotFound();
});

it('forbids the summary endpoint without read permission', function (): void {
    $writer = User::factory()->create();
    grantPatientCreatePermission($writer);
    $patient = $this->actingAs($writer)->postJson('/api/v1/patients', patientPayload())->json('data');

    $userWithoutRead = User::factory()->create();

    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/patients/'.$patient['id'].'/summary')
        ->assertForbidden();
});

it('includes contact details in the summary', function (): void {
    $user = makePatientReadUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/summary')
        ->assertOk()
        ->assertJsonPath('data.contact.email', 'amina@example.test')
        ->assertJsonPath('data.contact.addressLine', 'Msasani')
        ->assertJsonPath('data.contact.nextOfKinName', 'Juma Moshi');
});

it('surfaces the next scheduled appointment as upcomingAppointment', function (): void {
    $user = makePatientReadUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    AppointmentModel::query()->create([
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'patient_id' => $patient['id'],
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDays(3)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Follow-up',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);
    AppointmentModel::query()->create([
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'patient_id' => $patient['id'],
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subDays(10)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Past visit',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/summary')
        ->assertOk()
        ->assertJsonPath('data.upcomingAppointment.reason', 'Follow-up')
        ->assertJsonPath('data.stats.totalVisits', 2);
});

it('surfaces an in-progress visit as activeAppointment', function (): void {
    $user = makePatientReadUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $appointmentNumber = 'APT'.strtoupper(Str::random(8));
    AppointmentModel::query()->create([
        'appointment_number' => $appointmentNumber,
        'patient_id' => $patient['id'],
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Routine visit',
        'notes' => null,
        'status' => 'waiting_triage',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/summary')
        ->assertOk()
        ->assertJsonPath('data.activeAppointment.appointmentNumber', $appointmentNumber)
        ->assertJsonPath('data.activeAppointment.status', 'waiting_triage');
});

it('does not surface a completed visit or a merely-scheduled future booking as activeAppointment', function (): void {
    $user = makePatientReadUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    AppointmentModel::query()->create([
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'patient_id' => $patient['id'],
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Completed earlier today',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);
    // A merely-scheduled future booking is not "an active visit in
    // progress" for check-in purposes — it must not block/appear as if
    // the patient has already arrived (2026-08-12, duplicate-check-in fix
    // scoping: 'scheduled' deliberately excluded from
    // findActiveForPatient()'s status whitelist).
    AppointmentModel::query()->create([
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'patient_id' => $patient['id'],
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDays(3)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Future visit',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/summary')
        ->assertOk()
        ->assertJsonPath('data.activeAppointment', null);
});

it('surfaces an active visit as activeAppointment even when it started on a previous day', function (): void {
    // Regression test for the reported bug: a still-unresolved visit that
    // started yesterday (never completed) must still count as "the
    // patient already has an active visit" today — findActiveForPatient()
    // is deliberately not date-scoped, unlike findActiveForPatientOnDate().
    $user = makePatientReadUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $appointmentNumber = 'APT'.strtoupper(Str::random(8));
    AppointmentModel::query()->create([
        'appointment_number' => $appointmentNumber,
        'patient_id' => $patient['id'],
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Still waiting from yesterday',
        'notes' => null,
        'status' => 'waiting_provider',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/summary')
        ->assertOk()
        ->assertJsonPath('data.activeAppointment.appointmentNumber', $appointmentNumber)
        ->assertJsonPath('data.activeAppointment.status', 'waiting_provider');
});

it('surfaces a current inpatient admission', function (): void {
    $user = makePatientReadUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    AdmissionModel::query()->create([
        'admission_number' => 'ADM'.strtoupper(Str::random(8)),
        'patient_id' => $patient['id'],
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward A',
        'bed' => 'B-07',
        'admitted_at' => now()->subHours(3)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Observation',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/summary')
        ->assertOk()
        ->assertJsonPath('data.currentAdmission.ward', 'Ward A')
        ->assertJsonPath('data.currentAdmission.bed', 'B-07');
});

it('does not surface a discharged admission as current', function (): void {
    $user = makePatientReadUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    AdmissionModel::query()->create([
        'admission_number' => 'ADM'.strtoupper(Str::random(8)),
        'patient_id' => $patient['id'],
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward B',
        'bed' => 'B-02',
        'admitted_at' => now()->subDays(5)->toDateTimeString(),
        'discharged_at' => now()->subDays(1)->toDateTimeString(),
        'admission_reason' => 'Observation',
        'notes' => null,
        'status' => 'discharged',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/summary')
        ->assertOk()
        ->assertJsonPath('data.currentAdmission', null);
});

it('counts outstanding invoices and includes the latest invoice in recent activity', function (): void {
    $user = makePatientReadUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
        'patient_id' => $patient['id'],
        'invoice_date' => now()->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 10000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 10000,
        'paid_amount' => 0,
        'balance_amount' => 10000,
        'payment_due_at' => null,
        'line_items' => [],
        'status' => 'issued',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/summary')
        ->assertOk()
        ->assertJsonPath('data.stats.outstandingInvoices', 1);

    expect(collect($response->json('data.recentActivity'))->pluck('type'))->toContain('billing');
});

it('updates patient profile fields', function (): void {
    $user = makePatientManageUser();
    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$created['id'], [
            'phone' => '+255700999111',
            'addressLine' => 'Mbezi Beach',
        ])
        ->assertOk()
        ->assertJsonPath('data.phone', '+255700999111')
        ->assertJsonPath('data.addressLine', 'Mbezi Beach')
        ->assertJsonPath('warnings', []);
});

it('forbids patient update without update permission', function (): void {
    $user = makePatientReadUser();
    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$created['id'], [
            'phone' => '+255700999111',
        ])
        ->assertForbidden();
});

it('rejects status lifecycle fields on patient detail update endpoint', function (): void {
    $user = makePatientManageUser();
    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$created['id'], [
            'firstName' => 'ShouldNotPersist',
            'status' => 'inactive',
            'reason' => 'Lifecycle update attempt',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status', 'reason']);

    $patient = PatientModel::query()->findOrFail($created['id']);
    expect($patient->first_name)->toBe('Amina');
    expect($patient->status)->toBe('active');
});

it('allows patient update when phone belongs to another active patient but warns on possible duplicate demographics', function (): void {
    $user = makePatientManageUser();

    $first = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');
    $second = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload([
        'firstName' => 'Neema',
        'lastName' => 'Kisula',
        'phone' => '+255700123321',
        'email' => 'neema@example.test',
        'nationalId' => 'TZ-99887766',
    ]))->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$second['id'], [
            'phone' => '0700000001',
        ])
        ->assertOk()
        ->assertJsonPath('warnings.0.id', $first['id'])
        ->assertJsonPath('warnings.0.duplicateConfidenceLabel', 'possible');

    expect(PatientModel::query()->findOrFail($second['id'])->phone)->toBe('0700000001');
});

it('blocks patient update when national id belongs to another active patient', function (): void {
    $user = makePatientManageUser();

    $first = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');
    $second = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload([
        'firstName' => 'Neema',
        'lastName' => 'Kisula',
        'dateOfBirth' => '1988-02-14',
        'phone' => '+255700123321',
        'email' => 'neema@example.test',
        'nationalId' => 'TZ-99887766',
    ]))->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$second['id'], [
            'nationalId' => 'TZ123456789',
        ])
        ->assertConflict()
        ->assertJsonPath('duplicates.0.id', $first['id']);

    expect(PatientModel::query()->findOrFail($second['id'])->national_id)->toBe('TZ-99887766');
});

it('rejects clearing mandatory patient phone during update', function (): void {
    $user = makePatientManageUser();

    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$created['id'], [
            'phone' => null,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone']);
});

it('updates patient status', function (): void {
    $user = makePatientManageUser();
    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$created['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Duplicate profile review',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.statusReason', 'Duplicate profile review');
});

it('allows reactivating an inactive patient when only a shared phone belongs to another active patient', function (): void {
    $user = makePatientManageUser();

    $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->assertCreated();
    $inactive = PatientModel::query()->create([
        'patient_number' => 'PT20260507DUP001',
        'first_name' => 'Inactive',
        'middle_name' => null,
        'last_name' => 'Duplicate',
        'gender' => 'female',
        'date_of_birth' => '1990-05-07',
        'phone' => '0700000001',
        'email' => null,
        'national_id' => null,
        'country_code' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Ilala',
        'address_line' => 'Kariakoo',
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'inactive',
        'status_reason' => 'Duplicate profile review',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$inactive->id.'/status', [
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect($inactive->refresh()->status)->toBe('active');
});

it('forbids patient status update without status permission', function (): void {
    $user = makePatientReadUser();
    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$created['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Duplicate profile review',
        ])
        ->assertForbidden();
});

it('enforces reason for inactive status and writes transition metadata', function (): void {
    $user = makePatientManageUser();
    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$created['id'].'/status', [
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$created['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Duplicate profile review',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.statusReason', 'Duplicate profile review');

    $statusLog = PatientAuditLogModel::query()
        ->where('patient_id', $created['id'])
        ->where('action', 'patient.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('active');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('inactive');
    expect($statusLog?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('writes audit logs for create update and status change', function (): void {
    $user = makePatientManageUser();

    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)->patchJson('/api/v1/patients/'.$created['id'], [
        'phone' => '+255788100100',
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/patients/'.$created['id'].'/status', [
        'status' => 'inactive',
        'reason' => 'archived for merge',
    ])->assertOk();

    $logs = PatientAuditLogModel::query()
        ->where('patient_id', $created['id'])
        ->orderBy('created_at')
        ->get();

    expect($logs)->toHaveCount(3);
    expect($logs->pluck('action')->all())->toContain(
        'patient.created',
        'patient.updated',
        'patient.status.updated',
    );
    expect($logs->first()->actor_id)->toBe($user->id);
});

it('creates updates and lists patient allergy records', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/patients/'.$patient['id'].'/allergies', [
            'substanceCode' => 'ATC:N02BE01',
            'substanceName' => 'Paracetamol 500mg',
            'reaction' => 'Rash',
            'severity' => 'moderate',
            'notes' => 'Reported during prior outpatient visit.',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$patient['id'].'/allergies/'.$created['id'], [
            'severity' => 'severe',
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('data.severity', 'severe');

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/allergies?status=active')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.substanceName', 'Paracetamol 500mg')
        ->assertJsonPath('data.0.severity', 'severe');

    expect(
        PatientAuditLogModel::query()
            ->where('patient_id', $patient['id'])
            ->where('action', 'patient.allergy.created')
            ->exists()
    )->toBeTrue();
    expect(
        PatientAuditLogModel::query()
            ->where('patient_id', $patient['id'])
            ->where('action', 'patient.allergy.updated')
            ->exists()
    )->toBeTrue();
});

it('creates updates and lists patient medication profile records', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/patients/'.$patient['id'].'/medication-profile', [
            'medicationCode' => 'ATC:J01CA04',
            'medicationName' => 'Amoxicillin 500mg',
            'dose' => '500 mg',
            'route' => 'oral',
            'frequency' => 'three_times_daily',
            'source' => 'home_medication',
            'status' => 'active',
            'indication' => 'Recent respiratory infection',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$patient['id'].'/medication-profile/'.$created['id'], [
            'status' => 'stopped',
            'reconciliationNote' => 'Completed course at home.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'stopped')
        ->assertJsonPath('data.reconciliationNote', 'Completed course at home.');

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/medication-profile')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.medicationName', 'Amoxicillin 500mg');

    expect(
        PatientAuditLogModel::query()
            ->where('patient_id', $patient['id'])
            ->where('action', 'patient.medication-profile.created')
            ->exists()
    )->toBeTrue();
    expect(
        PatientAuditLogModel::query()
            ->where('patient_id', $patient['id'])
            ->where('action', 'patient.medication-profile.updated')
            ->exists()
    )->toBeTrue();
});

it('returns patient medication safety summary and reconciliation workspace', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    PatientAllergyModel::query()->create([
        'patient_id' => $patient['id'],
        'tenant_id' => null,
        'substance_code' => 'ATC:N02BE01',
        'substance_name' => 'Paracetamol 500mg',
        'reaction' => 'Rash',
        'severity' => 'severe',
        'status' => 'active',
    ]);

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient['id'],
        'tenant_id' => null,
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dose' => '500 mg',
        'route' => 'oral',
        'frequency' => 'twice_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => 'Take 1 tablet every 8 hours after meals',
        'quantity_prescribed' => 12,
        'quantity_dispensed' => 12,
        'dispensing_notes' => 'Released and awaiting reconciliation',
        'dispensed_at' => now()->subDay()->toDateTimeString(),
        'verified_at' => now()->subDay()->addHour()->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'reconciliation_status' => 'pending',
        'status' => 'dispensed',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/medication-safety-summary?medicationCode=ATC:N02BE01&medicationName=Paracetamol%20500mg')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.allergyConflicts')
        ->assertJsonCount(1, 'data.activeProfileMatches')
        ->assertJsonCount(1, 'data.matchingActiveOrders')
        ->assertJsonCount(1, 'data.unreconciledDispensedOrders')
        ->assertJsonPath('data.overrideOptions.0.code', 'benefit_outweighs_risk');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain(
            'allergy_match',
            'missing_clinical_indication',
            'active_therapy_duplicate',
            'unreconciled_released_medications',
        );
    expect($response->json('data.ruleCatalogVersion'))->toBe('pharmacy-medication-safety.v2');
    expect(collect($response->json('data.ruleGroups'))->pluck('key')->all())
        ->toContain('allergy', 'duplicate_therapy', 'reconciliation');

    $allergyRule = collect($response->json('data.rules'))->firstWhere('code', 'allergy_match');
    expect($allergyRule['source']['type'] ?? null)->toBe('patient_allergy_list');
    expect($allergyRule['source']['referenceLabel'] ?? null)->toBe('Paracetamol 500mg');

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/medication-reconciliation')
        ->assertOk()
        ->assertJsonPath('data.counts.activeAllergies', 1)
        ->assertJsonPath('data.counts.activeMedicationProfile', 1)
        ->assertJsonPath('data.counts.activeDispensedOrders', 1)
        ->assertJsonPath('data.counts.unreconciledDispensedOrders', 1);
});

it('does not require clinical indication when no medicine is being evaluated', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$patient['id'].'/medication-safety-summary')
        ->assertOk();

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->not->toContain('missing_clinical_indication');
});

it('returns medication dosing sanity rules when instruction or quantity looks unsafe', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:N02BE01'
            .'&medicationName=Paracetamol%20500mg'
            .'&clinicalIndication=Pain'
            .'&dosageInstruction=Take%202%20tablets'
            .'&quantityPrescribed=1',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain(
            'unclear_dosing_schedule',
            'quantity_less_than_single_dose',
        );
});

it('returns high-dose paracetamol alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:N02BE01'
            .'&medicationName=Paracetamol%20500mg'
            .'&clinicalIndication=Pain'
            .'&dosageInstruction=Take%203%20tablets%20every%204%20hours%20for%203%20days'
            .'&quantityPrescribed=54',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('paracetamol_daily_dose_above_max');
});

it('returns pediatric weight-based dose alerts when appointment triage includes weight', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload([
        'dateOfBirth' => now()->subYears(5)->toDateString(),
    ]))->json('data');

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'clinician_user_id' => null,
        'department' => 'Pediatrics',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Fever review',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
        'triage_vitals_summary' => 'Weight 18 kg, Temp 38.4 C, Pulse 110 bpm',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?appointmentId='.$appointment->id
            .'&medicationCode=ATC:N02BE01'
            .'&medicationName=Paracetamol%20500mg'
            .'&clinicalIndication=Fever'
            .'&dosageInstruction=Take%202%20tablets%20every%206%20hours%20for%203%20days'
            .'&quantityPrescribed=24',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonPath('data.patientContext.ageYears', 5)
        ->assertJsonPath('data.patientContext.weightKg', 18)
        ->assertJsonPath('data.patientContext.isPediatric', true);

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('paracetamol_weight_based_daily_dose_above_max');
});

it('returns pediatric amoxicillin dose alerts when weight-based dosing is too high', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload([
        'dateOfBirth' => now()->subYears(3)->toDateString(),
    ]))->json('data');

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'clinician_user_id' => null,
        'department' => 'Pediatrics',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Infection review',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
        'triage_vitals_summary' => 'Weight 15 kg, Temp 38.1 C',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?appointmentId='.$appointment->id
            .'&medicationCode=ATC:J01CA04'
            .'&medicationName=Amoxicillin%20500mg'
            .'&clinicalIndication=Pneumonia'
            .'&dosageInstruction=Take%201%20capsule%20every%208%20hours%20for%205%20days'
            .'&quantityPrescribed=15',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('amoxicillin_weight_based_daily_dose_above_max');
});

it('returns pediatric weight-missing review for amoxicillin in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload([
        'dateOfBirth' => now()->subYears(4)->toDateString(),
    ]))->json('data');

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:J01CA04'
            .'&medicationName=Amoxicillin%20500mg'
            .'&clinicalIndication=Otitis%20media'
            .'&dosageInstruction=Take%201%20capsule%20every%208%20hours%20for%205%20days'
            .'&quantityPrescribed=15',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('pediatric_weight_context_missing');
});

it('returns neonatal ceftriaxone review alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload([
        'dateOfBirth' => now()->subDays(21)->toDateString(),
    ]))->json('data');

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:J01DD04'
            .'&medicationName=Ceftriaxone%201g'
            .'&clinicalIndication=Sepsis'
            .'&dosageInstruction=Give%201%20vial%20every%2024%20hours'
            .'&quantityPrescribed=1',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('ceftriaxone_neonate_review_required');
});

it('returns salbutamol form-review alerts in patient medication safety summary when route is not clearly inhaled', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:R03AC02'
            .'&medicationName=Salbutamol%202mg%20tablets'
            .'&clinicalIndication=Bronchospasm'
            .'&dosageInstruction=Take%201%20tablet%20every%208%20hours'
            .'&quantityPrescribed=15',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('salbutamol_route_form_review_required');
});

it('returns ceftriaxone route-form mismatch alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:J01DD04'
            .'&medicationName=Ceftriaxone%201g'
            .'&clinicalIndication=Sepsis'
            .'&dosageInstruction=Take%201%20tablet%20every%2012%20hours%20for%205%20days'
            .'&quantityPrescribed=10',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('ceftriaxone_route_form_mismatch');
});

it('returns oxytocin route-form mismatch alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=MED-OXYT-10INJ'
            .'&medicationName=Oxytocin%2010%20IU'
            .'&clinicalIndication=Postpartum%20hemorrhage'
            .'&dosageInstruction=Take%201%20tablet%20every%208%20hours'
            .'&quantityPrescribed=3',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('oxytocin_route_form_mismatch');
});

it('returns metronidazole route-form mismatch alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=MED-METR-400TAB'
            .'&medicationName=Metronidazole%20400mg'
            .'&clinicalIndication=Anaerobic%20infection'
            .'&dosageInstruction=Infuse%20400mg%20over%2030%20minutes'
            .'&quantityPrescribed=21',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('metronidazole_route_form_mismatch');
});

it('returns artemether lumefantrine severe-malaria route review alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=MED-ALU-20-120TAB'
            .'&medicationName=Artemether%2FLumefantrine%2020mg%2F120mg'
            .'&clinicalIndication=Severe%20malaria'
            .'&dosageInstruction=Infuse%204%20tablets%20over%2030%20minutes'
            .'&quantityPrescribed=24',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain(
            'artemether_lumefantrine_route_form_mismatch',
            'artemether_lumefantrine_severe_malaria_review',
        );
});

it('returns medication interaction alerts from current medications and active pharmacy workflow', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient['id'],
        'tenant_id' => null,
        'medication_code' => 'ATC:C08CA01',
        'medication_name' => 'Amlodipine 5mg',
        'dose' => '5 mg',
        'route' => 'oral',
        'frequency' => 'once_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subHour()->toDateTimeString(),
        'medication_code' => 'ATC:C03CA01',
        'medication_name' => 'Furosemide 40mg',
        'dosage_instruction' => 'Take 1 tablet every morning',
        'clinical_indication' => 'Fluid overload',
        'quantity_prescribed' => 14,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:M01AE01'
            .'&medicationName=Ibuprofen%20400mg'
            .'&clinicalIndication=Pain'
            .'&dosageInstruction=Take%201%20tablet%20every%208%20hours%20for%203%20days'
            .'&quantityPrescribed=9',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning')
        ->assertJsonCount(2, 'data.interactionConflicts');

    expect(collect($response->json('data.interactionConflicts'))->pluck('ruleCode')->all())
        ->toContain(
            'interaction_ibuprofen_amlodipine',
            'interaction_ibuprofen_furosemide',
        );

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain(
            'interaction_ibuprofen_amlodipine',
            'interaction_ibuprofen_furosemide',
        );
});

it('returns diclofenac interaction alerts from current medications and active pharmacy workflow', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient['id'],
        'tenant_id' => null,
        'medication_code' => 'ATC:C09AA02',
        'medication_name' => 'Enalapril 5mg',
        'dose' => '5 mg',
        'route' => 'oral',
        'frequency' => 'once_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subHour()->toDateTimeString(),
        'medication_code' => 'ATC:C03CA01',
        'medication_name' => 'Furosemide 40mg',
        'dosage_instruction' => 'Take 1 tablet every morning',
        'clinical_indication' => 'Fluid overload',
        'quantity_prescribed' => 14,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:M01AB05'
            .'&medicationName=Diclofenac%2050mg'
            .'&clinicalIndication=Pain'
            .'&dosageInstruction=Take%201%20tablet%20every%208%20hours%20for%203%20days'
            .'&quantityPrescribed=9',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning')
        ->assertJsonCount(2, 'data.interactionConflicts');

    expect(collect($response->json('data.interactionConflicts'))->pluck('ruleCode')->all())
        ->toContain(
            'interaction_diclofenac_enalapril',
            'interaction_diclofenac_furosemide',
        );

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain(
            'interaction_diclofenac_enalapril',
            'interaction_diclofenac_furosemide',
        );
});

it('returns enalapril spironolactone interaction alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient['id'],
        'tenant_id' => null,
        'medication_code' => 'ATC:C09AA02',
        'medication_name' => 'Enalapril 5mg',
        'dose' => '5 mg',
        'route' => 'oral',
        'frequency' => 'once_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:C03DA01'
            .'&medicationName=Spironolactone%2025mg'
            .'&clinicalIndication=Heart%20failure'
            .'&dosageInstruction=Take%201%20tablet%20once%20daily'
            .'&quantityPrescribed=30',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning')
        ->assertJsonCount(1, 'data.interactionConflicts');

    expect(collect($response->json('data.interactionConflicts'))->pluck('ruleCode')->all())
        ->toContain('interaction_enalapril_spironolactone');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('interaction_enalapril_spironolactone');
});

it('returns spironolactone potassium-supplement interaction alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient['id'],
        'tenant_id' => null,
        'medication_code' => 'ATC:A12BA01',
        'medication_name' => 'Potassium Chloride 600mg',
        'dose' => '600 mg',
        'route' => 'oral',
        'frequency' => 'once_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:C03DA01'
            .'&medicationName=Spironolactone%2025mg'
            .'&clinicalIndication=Heart%20failure'
            .'&dosageInstruction=Take%201%20tablet%20once%20daily'
            .'&quantityPrescribed=30',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.interactionConflicts');

    expect(collect($response->json('data.interactionConflicts'))->pluck('ruleCode')->all())
        ->toContain('interaction_spironolactone_potassium_chloride');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('interaction_spironolactone_potassium_chloride');
});

it('returns enalapril potassium-supplement interaction alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient['id'],
        'tenant_id' => null,
        'medication_code' => 'ATC:A12BA01',
        'medication_name' => 'Potassium Chloride 600mg',
        'dose' => '600 mg',
        'route' => 'oral',
        'frequency' => 'once_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:C09AA02'
            .'&medicationName=Enalapril%205mg'
            .'&clinicalIndication=Hypertension'
            .'&dosageInstruction=Take%201%20tablet%20once%20daily'
            .'&quantityPrescribed=30',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning')
        ->assertJsonCount(1, 'data.interactionConflicts');

    expect(collect($response->json('data.interactionConflicts'))->pluck('ruleCode')->all())
        ->toContain('interaction_enalapril_potassium_chloride');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('interaction_enalapril_potassium_chloride');
});

it('returns co-trimoxazole spironolactone interaction alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient['id'],
        'tenant_id' => null,
        'medication_code' => 'ATC:C03DA01',
        'medication_name' => 'Spironolactone 25mg',
        'dose' => '25 mg',
        'route' => 'oral',
        'frequency' => 'once_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:J01EE01'
            .'&medicationName=Co-trimoxazole%20960mg'
            .'&clinicalIndication=Urinary%20tract%20infection'
            .'&dosageInstruction=Take%201%20tablet%20every%2012%20hours'
            .'&quantityPrescribed=14',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.interactionConflicts');

    expect(collect($response->json('data.interactionConflicts'))->pluck('ruleCode')->all())
        ->toContain('interaction_cotrimoxazole_spironolactone');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('interaction_cotrimoxazole_spironolactone');
});

it('returns metronidazole warfarin interaction alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient['id'],
        'tenant_id' => null,
        'medication_code' => 'ATC:B01AA03',
        'medication_name' => 'Warfarin 5mg',
        'dose' => '5 mg',
        'route' => 'oral',
        'frequency' => 'once_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=MED-METR-400TAB'
            .'&medicationName=Metronidazole%20400mg'
            .'&clinicalIndication=Anaerobic%20infection'
            .'&dosageInstruction=Take%201%20tablet%20every%208%20hours'
            .'&quantityPrescribed=21',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.interactionConflicts');

    expect(collect($response->json('data.interactionConflicts'))->pluck('ruleCode')->all())
        ->toContain('interaction_metronidazole_warfarin');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('interaction_metronidazole_warfarin');
});

it('returns laboratory result alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'LOINC:2823-3',
        'test_name' => 'Serum Potassium',
        'priority' => 'urgent',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Electrolyte review',
        'result_summary' => "Result Flag: critical\nMeasured Result: 2.4 mmol/L",
        'resulted_at' => now()->subHours(18)->toDateTimeString(),
        'verified_at' => now()->subHours(17)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Critical potassium verified.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:R03AC02'
            .'&medicationName=Salbutamol%20Inhaler'
            .'&clinicalIndication=Bronchospasm'
            .'&dosageInstruction=Use%202%20puffs%20every%206%20hours%20PRN'
            .'&quantityPrescribed=1',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_low_potassium_result');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_low_potassium_result');
});

it('returns furosemide electrolyte-depletion alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'LOINC:2823-3',
        'test_name' => 'Serum Potassium',
        'priority' => 'urgent',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Electrolyte review',
        'result_summary' => "Result Flag: critical\nMeasured Result: 2.8 mmol/L",
        'resulted_at' => now()->subHours(18)->toDateTimeString(),
        'verified_at' => now()->subHours(17)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Critical potassium verified.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:C03CA01'
            .'&medicationName=Furosemide%2040mg'
            .'&clinicalIndication=Fluid%20overload'
            .'&dosageInstruction=Take%201%20tablet%20every%20morning'
            .'&quantityPrescribed=14',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_low_potassium_result_furosemide_electrolyte_depletion');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_low_potassium_result_furosemide_electrolyte_depletion');
});

it('returns artemether lumefantrine low-potassium alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'LOINC:2823-3',
        'test_name' => 'Serum Potassium',
        'priority' => 'urgent',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Electrolyte review',
        'result_summary' => "Result Flag: critical\nMeasured Result: 2.7 mmol/L",
        'resulted_at' => now()->subHours(18)->toDateTimeString(),
        'verified_at' => now()->subHours(17)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Critical potassium verified.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=MED-ALU-20-120TAB'
            .'&medicationName=Artemether%2FLumefantrine%2020mg%2F120mg'
            .'&clinicalIndication=Uncomplicated%20malaria'
            .'&dosageInstruction=Take%204%20tablets%20with%20food%20now%20and%204%20tablets%20after%208%20hours%20then%20twice%20daily%20for%202%20days'
            .'&quantityPrescribed=24',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_low_potassium_result_artemether_lumefantrine_qt_review');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_low_potassium_result_artemether_lumefantrine_qt_review');
});

it('returns iron folic acid severe-anemia alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'LAB-HB-001',
        'test_name' => 'Hemoglobin',
        'priority' => 'urgent',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Anemia review',
        'result_summary' => "Result Flag: critical\nMeasured Result: 6.4 g/dL",
        'resulted_at' => now()->subHours(18)->toDateTimeString(),
        'verified_at' => now()->subHours(17)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Critical hemoglobin verified.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=MED-IRON-FOLTAB'
            .'&medicationName=Iron%20%2B%20Folic%20Acid'
            .'&clinicalIndication=Pregnancy%20anemia'
            .'&dosageInstruction=Take%201%20tablet%20once%20daily'
            .'&quantityPrescribed=30',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_severe_anemia_result_iron_folic_acid_review');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_severe_anemia_result_iron_folic_acid_review');
});

it('returns diclofenac renal review alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'RENAL:CREATININE',
        'test_name' => 'Serum Creatinine',
        'priority' => 'urgent',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Renal function review',
        'result_summary' => "Result Flag: abnormal\nMeasured Result: 182 umol/L",
        'resulted_at' => now()->subHours(12)->toDateTimeString(),
        'verified_at' => now()->subHours(11)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Renal result reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:M01AB05'
            .'&medicationName=Diclofenac%2050mg'
            .'&clinicalIndication=Pain'
            .'&dosageInstruction=Take%201%20tablet%20every%208%20hours%20for%203%20days'
            .'&quantityPrescribed=9',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_renal_risk_result_diclofenac_review');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_renal_risk_result_diclofenac_review');
});

it('returns nitrofurantoin low-clearance alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'RENAL:EGFR',
        'test_name' => 'eGFR',
        'priority' => 'urgent',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Renal function review',
        'result_summary' => "Result Flag: abnormal\nMeasured Result: 45 mL/min/1.73m2",
        'resulted_at' => now()->subHours(12)->toDateTimeString(),
        'verified_at' => now()->subHours(11)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Renal result reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:J01XE01'
            .'&medicationName=Nitrofurantoin%20100mg'
            .'&clinicalIndication=Urinary%20tract%20infection'
            .'&dosageInstruction=Take%201%20capsule%20twice%20daily%20for%205%20days'
            .'&quantityPrescribed=10',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_renal_risk_result_nitrofurantoin_low_clearance_review');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_renal_risk_result_nitrofurantoin_low_clearance_review');
});

it('returns metformin renal alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'RENAL:EGFR',
        'test_name' => 'eGFR',
        'priority' => 'urgent',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Renal function review',
        'result_summary' => "Result Flag: abnormal\nMeasured Result: 28 mL/min/1.73m2",
        'resulted_at' => now()->subHours(12)->toDateTimeString(),
        'verified_at' => now()->subHours(11)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Renal result reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:A10BA02'
            .'&medicationName=Metformin%20500mg'
            .'&clinicalIndication=Diabetes'
            .'&dosageInstruction=Take%201%20tablet%20twice%20daily'
            .'&quantityPrescribed=60',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_renal_risk_result_metformin_contraindicated_range');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_renal_risk_result_metformin_contraindicated_range');
});

it('returns amoxicillin renal interval alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'RENAL:EGFR',
        'test_name' => 'eGFR',
        'priority' => 'urgent',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Renal function review',
        'result_summary' => "Result Flag: critical\nMeasured Result: 8 mL/min/1.73m2",
        'resulted_at' => now()->subHours(12)->toDateTimeString(),
        'verified_at' => now()->subHours(11)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Critical renal result reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:J01CA04'
            .'&medicationName=Amoxicillin%20500mg'
            .'&clinicalIndication=Respiratory%20infection'
            .'&dosageInstruction=Take%201%20capsule%20every%208%20hours%20for%205%20days'
            .'&quantityPrescribed=15',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_renal_risk_result_amoxicillin_q24_review');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_renal_risk_result_amoxicillin_q24_review');
});

it('returns enalapril renal initial-dose review alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'RENAL:EGFR',
        'test_name' => 'eGFR',
        'priority' => 'urgent',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Renal function review',
        'result_summary' => "Result Flag: abnormal\nMeasured Result: 25 mL/min/1.73m2",
        'resulted_at' => now()->subHours(12)->toDateTimeString(),
        'verified_at' => now()->subHours(11)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Renal result reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:C09AA02'
            .'&medicationName=Enalapril%205mg'
            .'&clinicalIndication=Hypertension'
            .'&dosageInstruction=Take%201%20tablet%20once%20daily'
            .'&quantityPrescribed=30',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_renal_risk_result_enalapril_initial_dose_review');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_renal_risk_result_enalapril_initial_dose_review');
});

it('returns spironolactone hyperkalemia alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'LOINC:2823-3',
        'test_name' => 'Serum Potassium',
        'priority' => 'urgent',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Electrolyte review',
        'result_summary' => "Result Flag: critical\nMeasured Result: 6.1 mmol/L",
        'resulted_at' => now()->subHours(12)->toDateTimeString(),
        'verified_at' => now()->subHours(11)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Critical potassium reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:C03DA01'
            .'&medicationName=Spironolactone%2025mg'
            .'&clinicalIndication=Heart%20failure'
            .'&dosageInstruction=Take%201%20tablet%20once%20daily'
            .'&quantityPrescribed=30',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_high_potassium_result_spironolactone_contraindicated_range');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_high_potassium_result_spironolactone_contraindicated_range');
});

it('returns co-trimoxazole potassium and renal review alerts in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'LOINC:2823-3',
        'test_name' => 'Serum Potassium',
        'priority' => 'urgent',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Electrolyte review',
        'result_summary' => "Result Flag: high\nMeasured Result: 5.7 mmol/L",
        'resulted_at' => now()->subHours(12)->toDateTimeString(),
        'verified_at' => now()->subHours(11)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'High potassium reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient['id'],
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'LOINC:33914-3',
        'test_name' => 'Estimated Glomerular Filtration Rate',
        'priority' => 'routine',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Renal review',
        'result_summary' => "Result Flag: abnormal\nMeasured Result: 24 mL/min/1.73m2",
        'resulted_at' => now()->subHours(10)->toDateTimeString(),
        'verified_at' => now()->subHours(9)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Renal function reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?medicationCode=ATC:J01EE01'
            .'&medicationName=Co-trimoxazole%20960mg'
            .'&clinicalIndication=Urinary%20tract%20infection'
            .'&dosageInstruction=Take%201%20tablet%20every%2012%20hours'
            .'&quantityPrescribed=14',
        )
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain(
            'recent_high_potassium_result_cotrimoxazole_review',
            'recent_renal_risk_result_cotrimoxazole_advanced_renal_risk_review',
        );

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain(
            'recent_high_potassium_result_cotrimoxazole_review',
            'recent_renal_risk_result_cotrimoxazole_advanced_renal_risk_review',
        );
});

it('returns policy recommendation guidance in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'formulary_item',
        'code' => 'ATC:J01DD04',
        'name' => 'Ceftriaxone 1g',
        'department_id' => null,
        'category' => 'antibiotics',
        'unit' => 'vial',
        'description' => 'Restricted injectable antibiotic fixture',
        'metadata' => [
            'reviewMode' => 'policy_review_required',
            'substitutionAllowed' => true,
            'restrictionReason' => 'Broad-spectrum injectable antibiotic. Review indication and release path before dispensing.',
            'allowedIndicationKeywords' => ['severe infection', 'sepsis'],
            'preferredAlternatives' => ['Amoxicillin 500mg'],
        ],
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?approvedMedicineCatalogItemId='.$catalogItem->id
            .'&medicationCode=ATC:J01DD04'
            .'&medicationName=Ceftriaxone%201g'
            .'&clinicalIndication=Unclear%20infection'
            .'&dosageInstruction=Give%201%20vial%20every%2012%20hours%20for%205%20days'
            .'&quantityPrescribed=2',
        )
        ->assertOk()
        ->assertJsonPath('data.policyRecommendation.key', 'clarify_restricted_indication')
        ->assertJsonPath('data.policyRecommendation.suggestedDecisionStatus', 'restricted')
        ->assertJsonPath('data.policyRecommendation.preferredAlternatives.0', 'Amoxicillin 500mg');
});

it('returns indication-specific preferred alternatives in patient medication safety summary', function (): void {
    $user = makePatientSafetyUser();
    $patient = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'formulary_item',
        'code' => 'ATC:J01CR02',
        'name' => 'Amoxicillin Clavulanate 625mg',
        'department_id' => null,
        'category' => 'antibiotics',
        'unit' => 'tablet',
        'description' => 'Restricted antibiotic fixture',
        'metadata' => [
            'reviewMode' => 'policy_review_required',
            'substitutionAllowed' => true,
            'restrictionReason' => 'Reserve for selected infections after policy review.',
            'allowedIndicationKeywords' => ['urinary tract infection', 'pneumonia'],
            'preferredAlternatives' => ['Amoxicillin 500mg'],
            'preferredAlternativesByIndication' => [
                [
                    'keywords' => ['urinary tract infection', 'uti'],
                    'alternatives' => ['Nitrofurantoin 100mg'],
                ],
                [
                    'keywords' => ['pneumonia', 'respiratory infection'],
                    'alternatives' => ['Azithromycin 500mg'],
                ],
            ],
        ],
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson(
            '/api/v1/patients/'.$patient['id'].'/medication-safety-summary'
            .'?approvedMedicineCatalogItemId='.$catalogItem->id
            .'&medicationCode=ATC:J01CR02'
            .'&medicationName=Amoxicillin%20Clavulanate%20625mg'
            .'&clinicalIndication=Urinary%20tract%20infection'
            .'&dosageInstruction=Take%201%20tablet%20every%2012%20hours%20for%205%20days'
            .'&quantityPrescribed=10',
        )
        ->assertOk()
        ->assertJsonPath('data.policyRecommendation.preferredAlternatives.0', 'Nitrofurantoin 100mg');
});

it('lists patient audit logs when authorized', function (): void {
    $user = makePatientManageUser();
    $user->givePermissionTo('patients.view-audit-logs');
    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)->patchJson('/api/v1/patients/'.$created['id'], [
        'phone' => '+255711111111',
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/patients/'.$created['id'].'/status', [
        'status' => 'inactive',
        'reason' => 'validation test',
    ])->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$created['id'].'/audit-logs?perPage=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('data.0.action', 'patient.status.updated')
        ->assertJsonPath('data.0.actionLabel', 'Patient Status Updated')
        ->assertJsonPath('data.0.actorType', 'user')
        ->assertJsonPath('data.0.actor.displayName', $user->name)
        ->assertJsonPath('data.1.action', 'patient.updated');
});

it('lists patient activity feed with actor identity for the visible timeline', function (): void {
    $user = makePatientReadUser();
    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$created['id'].'/activity-feed?perPage=5')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'patient.created')
        ->assertJsonPath('data.0.actionLabel', 'Patient Registered')
        ->assertJsonPath('data.0.actorType', 'user')
        ->assertJsonPath('data.0.actor.displayName', $user->name);

    expect(array_key_exists('changes', $response->json('data.0')))->toBeFalse();
});

it('forbids patient audit log access without permission', function (): void {
    $user = makePatientReadUser();
    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('forbids patient audit logs even if user has permission when gate override denies', function (): void {
    Gate::define('patients.view-audit-logs', static fn (): bool => false);

    $user = makePatientReadUser();
    $user->givePermissionTo('patients.view-audit-logs');
    $created = $this->actingAs($user)->postJson('/api/v1/patients', patientPayload())->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('returns 404 for audit logs of unknown patient id', function (): void {
    $user = makePatientReadUser();
    $user->givePermissionTo('patients.view-audit-logs');

    $this->actingAs($user)
        ->getJson('/api/v1/patients/cf456ba7-fdf9-49ef-88f8-c68556e4f99d/audit-logs')
        ->assertNotFound();
});

it('lists and filters patients', function (): void {
    $user = makePatientReadUser();

    PatientModel::query()->create([
        'patient_number' => 'PT20260225AAAAAA',
        'first_name' => 'John',
        'middle_name' => null,
        'last_name' => 'Mushi',
        'gender' => 'male',
        'date_of_birth' => '1990-01-10',
        'phone' => '+255700000010',
        'email' => null,
        'national_id' => null,
        'country_code' => 'TZ',
        'region' => 'Kilimanjaro',
        'district' => 'Moshi',
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);

    PatientModel::query()->create([
        'patient_number' => 'PT20260225BBBBBB',
        'first_name' => 'Mariam',
        'middle_name' => null,
        'last_name' => 'Nyerere',
        'gender' => 'female',
        'date_of_birth' => '1987-05-13',
        'phone' => '+255700000020',
        'email' => null,
        'national_id' => null,
        'country_code' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Ilala',
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'inactive',
        'status_reason' => 'test',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/patients?q=John&status=active&gender=male&region=kilim&district=mosh')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.firstName', 'John')
        ->assertJsonPath('meta.total', 1);
});

it('searches patients case-insensitively across identity fields', function (): void {
    $user = makePatientReadUser();

    PatientModel::query()->create([
        'patient_number' => 'PT20260225NEEMAA',
        'first_name' => 'Neema',
        'middle_name' => 'Asha',
        'last_name' => 'Mollel',
        'gender' => 'female',
        'date_of_birth' => '1994-11-03',
        'phone' => '+255700123456',
        'email' => 'Neema.Mollel@example.test',
        'national_id' => 'TZ-NEEMA-001',
        'country_code' => 'TZ',
        'region' => 'Arusha',
        'district' => 'Arusha City',
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/patients?q=neema')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.firstName', 'Neema');

    $this->actingAs($user)
        ->getJson('/api/v1/patients?q=example.test')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.email', 'Neema.Mollel@example.test');

    $this->actingAs($user)
        ->getJson('/api/v1/patients?q=tz-neema-001')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.nationalId', 'TZ-NEEMA-001');
});

it('stamps patient tenant scope when created under resolved platform scope', function (): void {
    $user = makePatientReadUser();

    [$tenantId] = seedPatientPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-PAT',
        facilityName: 'Dar Registration',
    );

    $created = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZH',
            'X-Facility-Code' => 'DAR-PAT',
        ])
        ->postJson('/api/v1/patients', patientPayload([
            'phone' => '+255700000101',
            'email' => 'tenant-scope@example.test',
            'nationalId' => 'TZ-444444444',
        ]))
        ->assertCreated()
        ->json('data');

    $row = PatientModel::query()->findOrFail($created['id']);

    expect($row->tenant_id)->toBe($tenantId);
});

it('filters patient reads by tenant scope when platform multi tenant isolation is enabled', function (): void {
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makePatientManageUser();

    [$tenantId] = seedPatientPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-PAT',
        facilityName: 'Nairobi Registration',
    );

    [$otherTenantId] = seedPatientPlatformScopeFacility(
        tenantCode: 'UGH',
        tenantName: 'Uganda Health Group',
        countryCode: 'UG',
        facilityCode: 'KLA-PAT',
        facilityName: 'Kampala Registration',
    );

    $visible = PatientModel::query()->create([
        'tenant_id' => $tenantId,
        'patient_number' => 'PT20260225SCOPT1',
        'first_name' => 'Scoped',
        'middle_name' => null,
        'last_name' => 'Visible',
        'gender' => 'female',
        'date_of_birth' => '1994-02-10',
        'phone' => '+255700200001',
        'email' => null,
        'national_id' => null,
        'country_code' => 'KE',
        'region' => null,
        'district' => null,
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);

    $hidden = PatientModel::query()->create([
        'tenant_id' => $otherTenantId,
        'patient_number' => 'PT20260225SCOPT2',
        'first_name' => 'Scoped',
        'middle_name' => null,
        'last_name' => 'Hidden',
        'gender' => 'female',
        'date_of_birth' => '1994-02-11',
        'phone' => '+255700200002',
        'email' => null,
        'national_id' => null,
        'country_code' => 'UG',
        'region' => null,
        'district' => null,
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-PAT',
        ])
        ->getJson('/api/v1/patients?q=Scoped')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.lastName', 'Visible');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-PAT',
        ])
        ->getJson('/api/v1/patients/'.$hidden->id)
        ->assertNotFound();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-PAT',
        ])
        ->patchJson('/api/v1/patients/'.$hidden->id, [
            'phone' => '+255799000999',
        ])
        ->assertNotFound();
});

it('filters patient reads by tenant scope when enabled via feature flag override', function (): void {
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $user = makePatientReadUser();

    [$tenantId] = seedPatientPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-PAT',
        facilityName: 'Nairobi Registration',
    );

    [$otherTenantId] = seedPatientPlatformScopeFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-PAT',
        facilityName: 'Dar Registration',
    );

    DB::table('feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_name' => 'platform.multi_tenant_isolation',
        'scope_type' => 'country',
        'scope_key' => 'KE',
        'enabled' => true,
        'reason' => 'enable patient tenant isolation for Kenya rollout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $visible = PatientModel::query()->create([
        'tenant_id' => $tenantId,
        'patient_number' => 'PT20260225SCOPT3',
        'first_name' => 'Override',
        'middle_name' => null,
        'last_name' => 'Visible',
        'gender' => 'male',
        'date_of_birth' => '1992-03-10',
        'phone' => '+255700300001',
        'email' => null,
        'national_id' => null,
        'country_code' => 'KE',
        'region' => null,
        'district' => null,
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);

    PatientModel::query()->create([
        'tenant_id' => $otherTenantId,
        'patient_number' => 'PT20260225SCOPT4',
        'first_name' => 'Override',
        'middle_name' => null,
        'last_name' => 'Hidden',
        'gender' => 'male',
        'date_of_birth' => '1992-03-11',
        'phone' => '+255700300002',
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
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-PAT',
        ])
        ->getJson('/api/v1/patients?q=Override')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.lastName', 'Visible');
});

it('blocks patient creation in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makePatientReadUser();

    $this->actingAs($user)
        ->postJson('/api/v1/patients', patientPayload())
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

/**
 * @return array{0:string,1:string}
 */
function seedPatientPlatformScopeAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedPatientPlatformScopeFacility(
        tenantCode: $tenantCode,
        tenantName: $tenantName,
        countryCode: $countryCode,
        facilityCode: $facilityCode,
        facilityName: $facilityName,
    );

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'registration_clerk',
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
function seedPatientPlatformScopeFacility(
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

it('blocks patient update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makePatientManageUser();

    $patient = PatientModel::query()->create([
        'patient_number' => 'PT20260225GUARDP1',
        'first_name' => 'Guard',
        'middle_name' => null,
        'last_name' => 'Patient',
        'gender' => 'female',
        'date_of_birth' => '1996-04-21',
        'phone' => '+255700100001',
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
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$patient->id, [
            'phone' => '+255700100999',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks patient status update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makePatientManageUser();

    $patient = PatientModel::query()->create([
        'patient_number' => 'PT20260225GUARDP2',
        'first_name' => 'Guard',
        'middle_name' => null,
        'last_name' => 'Patient',
        'gender' => 'female',
        'date_of_birth' => '1996-04-21',
        'phone' => '+255700100002',
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
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$patient->id.'/status', [
            'status' => 'inactive',
            'reason' => 'Attempted guarded status update',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

