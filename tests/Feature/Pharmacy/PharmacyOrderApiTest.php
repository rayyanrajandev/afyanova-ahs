<?php

use App\Models\Permission;
use App\Models\User;
use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Jobs\GenerateAuditExportCsvJob;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientAllergyModel;
use App\Modules\Patient\Infrastructure\Models\PatientMedicationProfileModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Domain\Events\PharmacyOrderDispensed;
use App\Modules\Platform\Infrastructure\Models\AuditExportJobModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderAuditLogModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ensureActiveApprovedMedicineCatalogItem();
    ensureActiveApprovedMedicineCatalogItem([
        'code' => 'ATC:J01CA04',
        'name' => 'Amoxicillin 500mg',
        'category' => 'antibiotics',
        'unit' => 'capsule',
        'description' => 'Default amoxicillin fixture',
    ]);
});

function makePharmacyPatient(array $overrides = []): PatientModel
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

function makePharmacyAppointment(string $patientId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Prescription visit',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);
}

function makePharmacyAdmission(string $patientId): AdmissionModel
{
    return AdmissionModel::query()->create([
        'admission_number' => 'ADM'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward A',
        'bed' => 'A-02',
        'admitted_at' => now()->subHours(2)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Observation',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ]);
}

function ensureActiveApprovedMedicineCatalogItem(array $overrides = []): ClinicalCatalogItemModel
{
    $attributes = array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'formulary_item',
        'code' => 'ATC:N02BE01',
        'name' => 'Paracetamol 500mg',
        'department_id' => null,
        'category' => 'analgesics',
        'unit' => 'tablet',
        'description' => 'Default approved medicine fixture',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides);

    $match = [
        'tenant_id' => $attributes['tenant_id'],
        'facility_id' => $attributes['facility_id'],
        'catalog_type' => $attributes['catalog_type'],
        'code' => $attributes['code'],
    ];

    unset($attributes['tenant_id'], $attributes['facility_id'], $attributes['catalog_type'], $attributes['code']);

    return ClinicalCatalogItemModel::query()->firstOrCreate($match, $attributes);
}

function ensureIvFluidCatalogItem(array $overrides = []): ClinicalCatalogItemModel
{
    return ensureActiveApprovedMedicineCatalogItem(array_merge([
        'code' => 'ATC:IVFLUID-NS500',
        'name' => 'Normal Saline 0.9% 500 ml IV infusion',
        'category' => 'nutritional',
        'unit' => 'each',
        'description' => 'Isotonic sodium chloride solution for fluid and electrolyte replacement.',
        'dosage_form' => 'iv fluid',
        'strength' => '0.9%',
        'route' => 'intravenous',
        'container_volume_value' => 500,
        'container_volume_unit' => 'ml',
    ], $overrides));
}

function makePharmacyInventoryItem(array $overrides = []): InventoryItemModel
{
    $attributes = array_merge([
        'tenant_id' => null,
        'facility_id' => null,
        'item_code' => 'ATC:N02BE01',
        'item_name' => 'Paracetamol 500mg',
        'category' => 'pharmaceutical',
        'unit' => 'tablet',
        'current_stock' => 480,
        'reorder_level' => 120,
        'max_stock_level' => 800,
        'status' => 'active',
    ], $overrides);

    $catalogCategory = (string) ($overrides['category'] ?? 'analgesics');
    $attributes['category'] = 'pharmaceutical';
    $attributes['clinical_catalog_item_id'] = $attributes['clinical_catalog_item_id'] ?? ClinicalCatalogItemModel::query()->firstOrCreate(
        [
            'tenant_id' => $attributes['tenant_id'],
            'facility_id' => $attributes['facility_id'],
            'catalog_type' => 'formulary_item',
            'code' => $attributes['item_code'],
        ],
        [
            'name' => $attributes['item_name'],
            'department_id' => null,
            'category' => $catalogCategory,
            'unit' => $attributes['unit'],
            'description' => 'Auto-linked pharmacy inventory test fixture',
            'metadata' => null,
            'status' => 'active',
            'status_reason' => null,
        ],
    )->id;

    $item = InventoryItemModel::query()->create($attributes);

    // InventoryUnitConversionService requires an active base-unit record to resolve
    // a unit for dispensing at all (throws "Selected inventory unit could not be
    // resolved for this item." otherwise) — a requirement this fixture predates.
    // Real inventory items get this via a migration backfill; test fixtures need it
    // created explicitly.
    InventoryItemUnitModel::query()->create([
        'tenant_id' => $item->tenant_id,
        'facility_id' => $item->facility_id,
        'item_id' => $item->id,
        'unit_name' => $item->unit,
        'unit_code' => null,
        'base_quantity' => 1,
        'is_base_unit' => true,
        'is_default_sales_unit' => true,
        'is_default_purchase_unit' => true,
        'is_active' => true,
    ]);

    return $item;
}

function pharmacyOrderPayload(string $patientId, array $overrides = []): array
{
    return array_merge([
        'patientId' => $patientId,
        'orderedAt' => now()->toDateTimeString(),
        'medicationCode' => 'ATC:N02BE01',
        'medicationName' => 'Paracetamol 500mg',
        'dosageInstruction' => 'Take 1 tablet every 8 hours after meals',
        'clinicalIndication' => 'Fever and pain relief',
        'quantityPrescribed' => 12,
        'quantityDispensed' => 0,
        'dispensingNotes' => 'Awaiting pharmacist review',
    ], $overrides);
}

function grantPharmacyPermission(User $user, string $permission): void
{
    Permission::query()->firstOrCreate(['name' => $permission]);
    $user->givePermissionTo($permission);
}

function grantPharmacyPermissions(User $user, array $permissions): void
{
    foreach ($permissions as $permission) {
        grantPharmacyPermission($user, $permission);
    }
}

function grantPharmacyReadPermission(User $user): void
{
    grantPharmacyPermission($user, 'pharmacy.orders.read');
}

function makePharmacyUser(array $permissions = []): User
{
    $user = User::factory()->create();
    grantPharmacyPermissions($user, array_merge([
        'pharmacy.orders.read',
        'pharmacy.orders.create',
        'pharmacy.orders.update-status',
        'pharmacy.orders.verify-dispense',
        'pharmacy.orders.manage-policy',
        'pharmacy.orders.reconcile',
        'pharmacy-orders.view-audit-logs',
    ], $permissions));

    return $user;
}

function makePharmacyReadOnlyUser(): User
{
    $user = User::factory()->create();
    grantPharmacyReadPermission($user);

    return $user;
}

function createVerifiedDispensedPharmacyOrder(
    User $user,
    string $patientId,
    array $orderOverrides = []
): array {
    $medicationCode = (string) ($orderOverrides['medicationCode'] ?? 'ATC:N02BE01');
    $medicationName = (string) ($orderOverrides['medicationName'] ?? 'Paracetamol 500mg');

    makePharmacyInventoryItem([
        'item_code' => $medicationCode,
        'item_name' => $medicationName,
    ]);

    $created = test()
        ->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patientId, $orderOverrides),
            ['safetyAcknowledged' => true],
        ))
        ->assertCreated()
        ->json('data');

    test()
        ->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Prepared for dispensing.',
        ])
        ->assertOk();

    test()
        ->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'dispensingNotes' => 'Dispensed full quantity.',
        ])
        ->assertOk();

    return test()
        ->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Release verified.',
        ])
        ->assertOk()
        ->json('data');
}
it('dispatches PharmacyOrderDispensed when the order reaches dispensed', function (): void {
    Event::fake([PharmacyOrderDispensed::class]);

    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    makePharmacyInventoryItem([
        'item_code' => 'ATC:N02BE01',
        'item_name' => 'Paracetamol 500mg',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id),
            ['safetyAcknowledged' => true],
        ))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Prepared for dispensing.',
        ])
        ->assertOk();

    Event::assertNotDispatched(PharmacyOrderDispensed::class);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'dispensingNotes' => 'Dispensed full quantity.',
        ])
        ->assertOk();

    Event::assertDispatched(
        PharmacyOrderDispensed::class,
        fn (PharmacyOrderDispensed $event): bool => $event->pharmacyOrderId === $created['id']
            && $event->patientId === $patient->id
            && $event->orderedByUserId === $user->id,
    );
});

it('requires authentication for pharmacy order creation', function (): void {
    $patient = makePharmacyPatient();
    $this->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->assertUnauthorized();
});
it('forbids pharmacy order list without read permission', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders')
        ->assertForbidden();
});
it('forbids pharmacy order show without read permission', function (): void {
    $userWithRead = makePharmacyUser();
    $userWithoutRead = User::factory()->create();
    $patient = makePharmacyPatient();
    $created = $this->actingAs($userWithRead)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');
    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'])
        ->assertForbidden();
});

it('allows pharmacy operational users to read the approved medicines catalog without admin catalog permission', function (): void {
    $user = makePharmacyReadOnlyUser();

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/approved-medicines-catalog?status=active')
        ->assertOk()
        ->assertJsonFragment([
            'code' => 'ATC:N02BE01',
            'name' => 'Paracetamol 500mg',
        ]);
});

it('forbids approved medicines catalog access without pharmacy or admin catalog permissions', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/approved-medicines-catalog?status=active')
        ->assertForbidden();
});

it('can create pharmacy order for existing patient', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $appointment = makePharmacyAppointment($patient->id);
    $admission = makePharmacyAdmission($patient->id);
    $catalogItem = ensureActiveApprovedMedicineCatalogItem();
    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'approvedMedicineCatalogItemId' => $catalogItem->id,
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
            'orderedByUserId' => $user->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.approvedMedicineCatalogItemId', $catalogItem->id)
        ->assertJsonPath('data.formularyDecisionStatus', 'formulary')
        ->assertJsonPath('data.status', 'pending');
});

it('blocks pharmacy workflow transitions until restricted medicine policy review is completed', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $restrictedCatalogItem = ensureActiveApprovedMedicineCatalogItem([
        'code' => 'ATC:J01DD04',
        'name' => 'Ceftriaxone 1g',
        'category' => 'antibiotics',
        'unit' => 'vial',
        'description' => 'Restricted injectable antibiotic fixture',
        'metadata' => [
            'reviewMode' => 'policy_review_required',
            'restrictionReason' => 'Broad-spectrum injectable antibiotic. Review indication and release path before dispensing.',
            'allowedIndicationKeywords' => ['severe infection', 'sepsis'],
        ],
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:J01DD04',
        'item_name' => 'Ceftriaxone 1g',
        'unit' => 'vial',
        'current_stock' => 40,
        'reorder_level' => 10,
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id, [
                'approvedMedicineCatalogItemId' => $restrictedCatalogItem->id,
                'clinicalIndication' => 'Severe infection requiring parenteral therapy',
                'dosageInstruction' => 'Give 1 vial every 24 hours',
                'quantityPrescribed' => 2,
            ]),
            ['safetyAcknowledged' => true],
        ))
        ->assertCreated()
        ->assertJsonPath('data.medicationCode', 'ATC:J01DD04')
        ->assertJsonPath('data.formularyDecisionStatus', 'not_reviewed')
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Attempting to start restricted preparation.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['policy']);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/policy', [
            'formularyDecisionStatus' => 'formulary',
            'substitutionAllowed' => false,
            'substitutionMade' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.formularyDecisionStatus', 'formulary');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Policy reviewed and preparation started.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_preparation');
});

it('requires safety acknowledgement before creating an active pharmacy order with warning context', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient->id,
        'tenant_id' => null,
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dose' => '500 mg',
        'route' => 'oral',
        'frequency' => 'twice_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    $payload = pharmacyOrderPayload($patient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['safetyAcknowledged']);

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge($payload, [
            'safetyAcknowledged' => true,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');
});

it('requires clinical indication before creating an active pharmacy order', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id),
            ['clinicalIndication' => ''],
        ))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['clinicalIndication']);
});

it('requires override category and reason before creating an active pharmacy order with blocker context', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    PatientAllergyModel::query()->create([
        'patient_id' => $patient->id,
        'tenant_id' => null,
        'substance_code' => 'ATC:N02BE01',
        'substance_name' => 'Paracetamol 500mg',
        'reaction' => 'Anaphylaxis',
        'severity' => 'life_threatening',
        'status' => 'active',
    ]);

    $payload = pharmacyOrderPayload($patient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['safetyOverrideCode']);

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge($payload, [
            'safetyOverrideCode' => 'benefit_outweighs_risk',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['safetyOverrideReason']);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge($payload, [
            'safetyOverrideCode' => 'benefit_outweighs_risk',
            'safetyOverrideReason' => 'Benefits outweigh the documented allergy while urgent clinician review continues.',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->json('data');

    $audit = PharmacyOrderAuditLogModel::query()
        ->where('pharmacy_order_id', $created['id'])
        ->where('action', 'pharmacy-order.created')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit?->metadata['medication_safety_review']['override_code'] ?? null)
        ->toBe('benefit_outweighs_risk');
    expect($audit?->metadata['medication_safety_review']['rule_codes'] ?? [])
        ->toContain('allergy_match');
    expect($audit?->metadata['medication_safety_review']['rule_catalog_version'] ?? null)
        ->toBe('pharmacy-medication-safety.v2');
    expect($audit?->metadata['medication_safety_review']['override_summary']['applied'] ?? null)
        ->toBeTrue();
    expect($audit?->metadata['medication_safety_review']['override_summary']['label'] ?? null)
        ->toBe('Benefit outweighs risk');
    expect($audit?->metadata['medication_safety_review']['override_summary']['overriddenRuleCodes'] ?? [])
        ->toContain('allergy_match');
});
it('rejects pharmacy order when medicine is not available in the active approved medicines catalog', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'medicationCode' => 'ATC:UNKNOWN',
            'medicationName' => 'Unknown Medicine',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['medicationCode']);
});
it('rejects pharmacy order for missing patient', function (): void {
    $user = makePharmacyUser();
    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload((string) Str::uuid()))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['patientId']);
});
it('rejects pharmacy order when appointment does not belong to patient', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $otherPatient = makePharmacyPatient([
        'phone' => '+255788889999',
        'first_name' => 'Other',
        'last_name' => 'Patient',
    ]);
    $appointment = makePharmacyAppointment($otherPatient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'appointmentId' => $appointment->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['appointmentId']);
});

it('rejects pharmacy order when admission does not belong to patient', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $otherPatient = makePharmacyPatient([
        'phone' => '+255766667777',
        'first_name' => 'Third',
        'last_name' => 'Patient',
    ]);
    $admission = makePharmacyAdmission($otherPatient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'admissionId' => $admission->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['admissionId']);
});

it('fetches pharmacy order by id', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.id', $created['id']);
});

it('returns 404 for unknown pharmacy order id', function (): void {
    $user = makePharmacyUser();

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/060afc03-2ce9-4b1d-a1c2-326d2722ce25')
        ->assertNotFound();
});

it('returns pharmacy safety review context with blockers warnings and related orders', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $appointment = makePharmacyAppointment($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'appointmentId' => $appointment->id,
            'quantityPrescribed' => 12,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/policy', [
            'formularyDecisionStatus' => 'restricted',
            'formularyDecisionReason' => 'Restricted until pharmacist review is completed.',
            'substitutionAllowed' => false,
            'substitutionMade' => false,
        ])
        ->assertOk();

    PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'admission_id' => null,
        'approved_medicine_catalog_item_id' => $created['approvedMedicineCatalogItemId'],
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subMinutes(30)->toDateTimeString(),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => 'Take 1 tablet twice daily',
        'quantity_prescribed' => 10,
        'quantity_dispensed' => 0,
        'dispensing_notes' => 'Duplicate encounter order',
        'dispensed_at' => null,
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'approved_medicine_catalog_item_id' => $created['approvedMedicineCatalogItemId'],
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDays(7)->toDateTimeString(),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => 'Take 1 tablet at night',
        'quantity_prescribed' => 6,
        'quantity_dispensed' => 0,
        'dispensing_notes' => 'Recent patient duplicate',
        'dispensed_at' => null,
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'medication_code' => 'ATC:J01CA04',
        'medication_name' => 'Amoxicillin 500mg',
        'dosage_instruction' => 'Take 1 capsule three times daily',
        'quantity_prescribed' => 21,
        'quantity_dispensed' => 21,
        'dispensing_notes' => 'Awaiting reconciliation',
        'dispensed_at' => now()->subDay()->toDateTimeString(),
        'verified_at' => now()->subDay()->addHour()->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'reconciliation_status' => 'pending',
        'status' => 'dispensed',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    PatientAllergyModel::query()->create([
        'patient_id' => $patient->id,
        'tenant_id' => null,
        'substance_code' => 'ATC:N02BE01',
        'substance_name' => 'Paracetamol 500mg',
        'reaction' => 'Rash',
        'severity' => 'severe',
        'status' => 'active',
    ]);

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient->id,
        'tenant_id' => null,
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dose' => '500 mg',
        'route' => 'oral',
        'frequency' => 'twice_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:N02BE01',
        'item_name' => 'Paracetamol 500mg',
        'current_stock' => 0,
        'reorder_level' => 5,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'].'/safety-review');

    $response->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonPath('data.dispenseInventory.stockState', 'out_of_stock')
        ->assertJsonCount(1, 'data.allergyConflicts')
        ->assertJsonCount(1, 'data.activeProfileMatches')
        ->assertJsonCount(2, 'data.matchingActiveOrders')
        ->assertJsonCount(1, 'data.sameEncounterDuplicates')
        ->assertJsonCount(1, 'data.recentPatientDuplicates')
        ->assertJsonCount(1, 'data.unreconciledReleasedOrders');

    expect($response->json('data.blockers'))->toContain(
        'Policy review is still required before this medication can move to pharmacist release.',
        'Dispense stock is currently zero for the medicine selected for release.',
    );
    expect($response->json('data.warnings'))->toContain(
        'This medicine is already present in the current medication list or pharmacy workflow.',
        'Another active order for the same medicine is already open in this encounter.',
        'The patient has recent orders for the same medicine within the last 30 days.',
        'The patient still has previously dispensed medication orders awaiting reconciliation follow-up.',
    );
    expect($response->json('data.blockers'))->toContain(
        'Active allergy or intolerance matches the medicine selected for release.',
    );
    expect($response->json('data.ruleCatalogVersion'))->toBe('pharmacy-medication-safety.v2');
    expect(collect($response->json('data.ruleGroups'))->pluck('key')->all())
        ->toContain('policy', 'inventory', 'allergy');

    $inventoryRule = collect($response->json('data.rules'))->firstWhere('code', 'inventory_out_of_stock');
    expect($inventoryRule['source']['type'] ?? null)->toBe('inventory_stock');
    expect($inventoryRule['source']['referenceLabel'] ?? null)->toBe('Paracetamol 500mg');
});

it('returns 404 for unknown pharmacy safety review id', function (): void {
    $user = makePharmacyUser();

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/060afc03-2ce9-4b1d-a1c2-326d2722ce25/safety-review')
        ->assertNotFound();
});

it('returns policy recommendation guidance in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $restrictedCatalogItem = ensureActiveApprovedMedicineCatalogItem([
        'code' => 'ATC:J01DD04',
        'name' => 'Ceftriaxone 1g',
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
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:J01DD04',
        'item_name' => 'Ceftriaxone 1g',
        'unit' => 'vial',
        'current_stock' => 20,
        'reorder_level' => 5,
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id, [
                'approvedMedicineCatalogItemId' => $restrictedCatalogItem->id,
                'clinicalIndication' => 'Unclear infection',
                'dosageInstruction' => 'Give 1 vial every 24 hours',
                'quantityPrescribed' => 2,
            ]),
            ['safetyAcknowledged' => true],
        ))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'].'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.policyRecommendation.key', 'clarify_restricted_indication')
        ->assertJsonPath('data.policyRecommendation.suggestedDecisionStatus', 'restricted')
        ->assertJsonPath('data.policyRecommendation.preferredAlternatives.0', 'Amoxicillin 500mg');
});

it('returns indication-specific preferred alternatives in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $restrictedCatalogItem = ensureActiveApprovedMedicineCatalogItem([
        'code' => 'ATC:J01CR02',
        'name' => 'Amoxicillin Clavulanate 625mg',
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
            ],
        ],
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:J01CR02',
        'item_name' => 'Amoxicillin Clavulanate 625mg',
        'unit' => 'tablet',
        'current_stock' => 20,
        'reorder_level' => 5,
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id, [
                'approvedMedicineCatalogItemId' => $restrictedCatalogItem->id,
                'medicationCode' => 'ATC:J01CR02',
                'medicationName' => 'Amoxicillin Clavulanate 625mg',
                'clinicalIndication' => 'Urinary tract infection',
                'quantityPrescribed' => 10,
            ]),
            ['safetyAcknowledged' => true],
        ))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'].'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.policyRecommendation.preferredAlternatives.0', 'Nitrofurantoin 100mg');
});

it('returns oxytocin restricted-use guidance in pharmacy safety review even when catalog metadata is incomplete', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $oxytocinCatalogItem = ensureActiveApprovedMedicineCatalogItem([
        'code' => 'MED-OXYT-10INJ',
        'name' => 'Oxytocin 10 IU',
        'category' => 'maternal_health',
        'unit' => 'ampoule',
        'description' => 'Maternal health uterotonic fixture',
        'metadata' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'MED-OXYT-10INJ',
        'item_name' => 'Oxytocin 10 IU',
        'unit' => 'ampoule',
        'current_stock' => 20,
        'reorder_level' => 5,
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id, [
                'approvedMedicineCatalogItemId' => $oxytocinCatalogItem->id,
                'medicationCode' => 'MED-OXYT-10INJ',
                'medicationName' => 'Oxytocin 10 IU',
                'clinicalIndication' => 'Hypertension',
                'dosageInstruction' => 'Infuse 10 IU in IV fluids once',
                'quantityPrescribed' => 1,
            ]),
            ['safetyAcknowledged' => true],
        ))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'].'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.policyRecommendation.key', 'clarify_restricted_indication')
        ->assertJsonPath('data.policyRecommendation.suggestedDecisionStatus', 'restricted')
        ->assertJsonPath('data.policyRecommendation.indicationMatched', false);
});

it('returns high-dose ibuprofen alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $catalogItem = ensureActiveApprovedMedicineCatalogItem([
        'code' => 'ATC:M01AE01',
        'name' => 'Ibuprofen 400mg',
        'category' => 'analgesics',
        'unit' => 'tablet',
        'description' => 'Ibuprofen fixture',
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:M01AE01',
        'item_name' => 'Ibuprofen 400mg',
        'unit' => 'tablet',
        'current_stock' => 120,
        'reorder_level' => 20,
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id, [
                'approvedMedicineCatalogItemId' => $catalogItem->id,
                'medicationCode' => 'ATC:M01AE01',
                'medicationName' => 'Ibuprofen 400mg',
                'dosageInstruction' => 'Take 2 tablets every 4 hours for 5 days',
                'clinicalIndication' => 'Severe pain',
                'quantityPrescribed' => 60,
            ]),
            [
                'safetyAcknowledged' => true,
                'safetyOverrideCode' => 'benefit_outweighs_risk',
                'safetyOverrideReason' => 'Temporary analgesic override for severe acute pain while dose is being reviewed.',
            ],
        ))
        ->assertCreated()
        ->json('data');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'].'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('ibuprofen_daily_dose_above_max');
});

it('returns pediatric weight-based dose alerts in pharmacy safety review when appointment triage includes weight', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient([
        'date_of_birth' => now()->subYears(4)->toDateString(),
    ]);
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Pediatrics',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Pain review',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
        'triage_vitals_summary' => 'Weight 16 kg, Temp 37.1 C',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => 'Take 2 tablets every 6 hours for 3 days',
        'clinical_indication' => 'Pain',
        'quantity_prescribed' => 24,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonPath('data.patientContext.ageYears', 4)
        ->assertJsonPath('data.patientContext.weightKg', 16)
        ->assertJsonPath('data.patientContext.isPediatric', true);

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('paracetamol_weight_based_daily_dose_above_max');
});

it('returns pediatric amoxicillin dose alerts in pharmacy safety review when weight-based dosing is too high', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient([
        'date_of_birth' => now()->subYears(3)->toDateString(),
    ]);
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
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

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:J01CA04',
        'medication_name' => 'Amoxicillin 500mg',
        'dosage_instruction' => 'Take 1 capsule every 8 hours for 5 days',
        'clinical_indication' => 'Pneumonia',
        'quantity_prescribed' => 15,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:J01CA04',
        'item_name' => 'Amoxicillin 500mg',
        'unit' => 'capsule',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('amoxicillin_weight_based_daily_dose_above_max');
});

it('returns pediatric weight-missing review for amoxicillin in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient([
        'date_of_birth' => now()->subYears(4)->toDateString(),
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:J01CA04',
        'medication_name' => 'Amoxicillin 500mg',
        'dosage_instruction' => 'Take 1 capsule every 8 hours for 5 days',
        'clinical_indication' => 'Otitis media',
        'quantity_prescribed' => 15,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:J01CA04',
        'item_name' => 'Amoxicillin 500mg',
        'unit' => 'capsule',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('pediatric_weight_context_missing');
});

it('returns neonatal ceftriaxone review alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient([
        'date_of_birth' => now()->subDays(21)->toDateString(),
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:J01DD04',
        'medication_name' => 'Ceftriaxone 1g',
        'dosage_instruction' => 'Give 1 vial every 24 hours',
        'clinical_indication' => 'Sepsis',
        'quantity_prescribed' => 1,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:J01DD04',
        'item_name' => 'Ceftriaxone 1g',
        'unit' => 'vial',
        'current_stock' => 20,
        'reorder_level' => 5,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('ceftriaxone_neonate_review_required');
});

it('returns salbutamol form-review alerts in pharmacy safety review when route is not clearly inhaled', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:R03AC02',
        'medication_name' => 'Salbutamol 2mg tablets',
        'dosage_instruction' => 'Take 1 tablet every 8 hours',
        'clinical_indication' => 'Bronchospasm',
        'quantity_prescribed' => 15,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:R03AC02',
        'item_name' => 'Salbutamol 2mg tablets',
        'unit' => 'tablet',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('salbutamol_route_form_review_required');
});

it('returns ceftriaxone route-form mismatch alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:J01DD04',
        'medication_name' => 'Ceftriaxone 1g',
        'dosage_instruction' => 'Take 1 tablet every 12 hours for 5 days',
        'clinical_indication' => 'Sepsis',
        'quantity_prescribed' => 10,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:J01DD04',
        'item_name' => 'Ceftriaxone 1g',
        'unit' => 'vial',
        'current_stock' => 20,
        'reorder_level' => 5,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('ceftriaxone_route_form_mismatch');
});

it('returns oxytocin route-form mismatch alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'MED-OXYT-10INJ',
        'medication_name' => 'Oxytocin 10 IU',
        'dosage_instruction' => 'Take 1 tablet every 8 hours',
        'clinical_indication' => 'Postpartum hemorrhage',
        'quantity_prescribed' => 3,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'not_reviewed',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'MED-OXYT-10INJ',
        'item_name' => 'Oxytocin 10 IU',
        'unit' => 'ampoule',
        'current_stock' => 20,
        'reorder_level' => 5,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('oxytocin_route_form_mismatch');
});

it('returns metronidazole route-form mismatch alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'MED-METR-400TAB',
        'medication_name' => 'Metronidazole 400mg',
        'dosage_instruction' => 'Infuse 400mg over 30 minutes',
        'clinical_indication' => 'Anaerobic infection',
        'quantity_prescribed' => 21,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'MED-METR-400TAB',
        'item_name' => 'Metronidazole 400mg',
        'unit' => 'tablet',
        'current_stock' => 40,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('metronidazole_route_form_mismatch');
});

it('returns artemether lumefantrine food guidance alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'MED-ALU-20-120TAB',
        'medication_name' => 'Artemether/Lumefantrine 20mg/120mg',
        'dosage_instruction' => 'Take 4 tablets now and 4 tablets after 8 hours then twice daily for 2 days',
        'clinical_indication' => 'Uncomplicated malaria',
        'quantity_prescribed' => 24,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'MED-ALU-20-120TAB',
        'item_name' => 'Artemether/Lumefantrine 20mg/120mg',
        'unit' => 'tablet',
        'current_stock' => 40,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('artemether_lumefantrine_food_administration_review');
});

it('returns medication interaction alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient->id,
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
        'patient_id' => $patient->id,
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

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:M01AE01',
        'medication_name' => 'Ibuprofen 400mg',
        'dosage_instruction' => 'Take 1 tablet every 8 hours for 3 days',
        'clinical_indication' => 'Pain',
        'quantity_prescribed' => 9,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:M01AE01',
        'item_name' => 'Ibuprofen 400mg',
        'current_stock' => 20,
        'reorder_level' => 5,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
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

it('returns diclofenac interaction alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient->id,
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
        'patient_id' => $patient->id,
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

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:M01AB05',
        'medication_name' => 'Diclofenac 50mg',
        'dosage_instruction' => 'Take 1 tablet every 8 hours for 3 days',
        'clinical_indication' => 'Pain',
        'quantity_prescribed' => 9,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:M01AB05',
        'item_name' => 'Diclofenac 50mg',
        'current_stock' => 20,
        'reorder_level' => 5,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
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

it('returns enalapril spironolactone interaction alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient->id,
        'tenant_id' => null,
        'medication_code' => 'ATC:C09AA02',
        'medication_name' => 'Enalapril 5mg',
        'dose' => '5 mg',
        'route' => 'oral',
        'frequency' => 'once_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:C03DA01',
        'medication_name' => 'Spironolactone 25mg',
        'dosage_instruction' => 'Take 1 tablet once daily',
        'clinical_indication' => 'Heart failure',
        'quantity_prescribed' => 30,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:C03DA01',
        'item_name' => 'Spironolactone 25mg',
        'unit' => 'tablet',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning')
        ->assertJsonCount(1, 'data.interactionConflicts');

    expect(collect($response->json('data.interactionConflicts'))->pluck('ruleCode')->all())
        ->toContain('interaction_enalapril_spironolactone');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('interaction_enalapril_spironolactone');
});

it('returns spironolactone potassium-supplement interaction alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient->id,
        'tenant_id' => null,
        'medication_code' => 'ATC:A12BA01',
        'medication_name' => 'Potassium Chloride 600mg',
        'dose' => '600 mg',
        'route' => 'oral',
        'frequency' => 'once_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:C03DA01',
        'medication_name' => 'Spironolactone 25mg',
        'dosage_instruction' => 'Take 1 tablet once daily',
        'clinical_indication' => 'Heart failure',
        'quantity_prescribed' => 30,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:C03DA01',
        'item_name' => 'Spironolactone 25mg',
        'unit' => 'tablet',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.interactionConflicts');

    expect(collect($response->json('data.interactionConflicts'))->pluck('ruleCode')->all())
        ->toContain('interaction_spironolactone_potassium_chloride');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('interaction_spironolactone_potassium_chloride');
});

it('returns enalapril potassium-supplement interaction alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient->id,
        'tenant_id' => null,
        'medication_code' => 'ATC:A12BA01',
        'medication_name' => 'Potassium Chloride 600mg',
        'dose' => '600 mg',
        'route' => 'oral',
        'frequency' => 'once_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:C09AA02',
        'medication_name' => 'Enalapril 5mg',
        'dosage_instruction' => 'Take 1 tablet once daily',
        'clinical_indication' => 'Hypertension',
        'quantity_prescribed' => 30,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:C09AA02',
        'item_name' => 'Enalapril 5mg',
        'unit' => 'tablet',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning')
        ->assertJsonCount(1, 'data.interactionConflicts');

    expect(collect($response->json('data.interactionConflicts'))->pluck('ruleCode')->all())
        ->toContain('interaction_enalapril_potassium_chloride');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('interaction_enalapril_potassium_chloride');
});

it('returns co-trimoxazole spironolactone interaction alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient->id,
        'tenant_id' => null,
        'medication_code' => 'ATC:C03DA01',
        'medication_name' => 'Spironolactone 25mg',
        'dose' => '25 mg',
        'route' => 'oral',
        'frequency' => 'once_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:J01EE01',
        'medication_name' => 'Co-trimoxazole 960mg',
        'dosage_instruction' => 'Take 1 tablet every 12 hours',
        'clinical_indication' => 'Urinary tract infection',
        'quantity_prescribed' => 14,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:J01EE01',
        'item_name' => 'Co-trimoxazole 960mg',
        'unit' => 'tablet',
        'current_stock' => 40,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.interactionConflicts');

    expect(collect($response->json('data.interactionConflicts'))->pluck('ruleCode')->all())
        ->toContain('interaction_cotrimoxazole_spironolactone');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('interaction_cotrimoxazole_spironolactone');
});

it('returns metronidazole warfarin interaction alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient->id,
        'tenant_id' => null,
        'medication_code' => 'ATC:B01AA03',
        'medication_name' => 'Warfarin 5mg',
        'dose' => '5 mg',
        'route' => 'oral',
        'frequency' => 'once_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'MED-METR-400TAB',
        'medication_name' => 'Metronidazole 400mg',
        'dosage_instruction' => 'Take 1 tablet every 8 hours',
        'clinical_indication' => 'Anaerobic infection',
        'quantity_prescribed' => 21,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'MED-METR-400TAB',
        'item_name' => 'Metronidazole 400mg',
        'unit' => 'tablet',
        'current_stock' => 40,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.interactionConflicts');

    expect(collect($response->json('data.interactionConflicts'))->pluck('ruleCode')->all())
        ->toContain('interaction_metronidazole_warfarin');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('interaction_metronidazole_warfarin');
});

it('returns laboratory result alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'RENAL:CREATININE',
        'test_name' => 'Serum Creatinine',
        'priority' => 'routine',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Renal review',
        'result_summary' => "Result Flag: abnormal\nMeasured Result: 182 umol/L",
        'resulted_at' => now()->subHours(14)->toDateTimeString(),
        'verified_at' => now()->subHours(13)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Renal result reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:M01AE01',
        'medication_name' => 'Ibuprofen 400mg',
        'dosage_instruction' => 'Take 1 tablet every 8 hours for 3 days',
        'clinical_indication' => 'Pain',
        'quantity_prescribed' => 9,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:M01AE01',
        'item_name' => 'Ibuprofen 400mg',
        'current_stock' => 20,
        'reorder_level' => 5,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_renal_risk_result');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_renal_risk_result');
});

it('returns furosemide electrolyte-depletion alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'LOINC:2823-3',
        'test_name' => 'Serum Potassium',
        'priority' => 'routine',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Electrolyte review',
        'result_summary' => "Result Flag: critical\nMeasured Result: 2.8 mmol/L",
        'resulted_at' => now()->subHours(14)->toDateTimeString(),
        'verified_at' => now()->subHours(13)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Critical potassium reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
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

    makePharmacyInventoryItem([
        'item_code' => 'ATC:C03CA01',
        'item_name' => 'Furosemide 40mg',
        'unit' => 'tablet',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_low_potassium_result_furosemide_electrolyte_depletion');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_low_potassium_result_furosemide_electrolyte_depletion');
});

it('returns artemether lumefantrine low-potassium alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'LOINC:2823-3',
        'test_name' => 'Serum Potassium',
        'priority' => 'routine',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Electrolyte review',
        'result_summary' => "Result Flag: critical\nMeasured Result: 2.7 mmol/L",
        'resulted_at' => now()->subHours(14)->toDateTimeString(),
        'verified_at' => now()->subHours(13)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Critical potassium reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'MED-ALU-20-120TAB',
        'medication_name' => 'Artemether/Lumefantrine 20mg/120mg',
        'dosage_instruction' => 'Take 4 tablets with food now and 4 tablets after 8 hours then twice daily for 2 days',
        'clinical_indication' => 'Uncomplicated malaria',
        'quantity_prescribed' => 24,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'MED-ALU-20-120TAB',
        'item_name' => 'Artemether/Lumefantrine 20mg/120mg',
        'unit' => 'tablet',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_low_potassium_result_artemether_lumefantrine_qt_review');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_low_potassium_result_artemether_lumefantrine_qt_review');
});

it('returns iron folic acid severe-anemia alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'LAB-HB-001',
        'test_name' => 'Hemoglobin',
        'priority' => 'routine',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Anemia review',
        'result_summary' => "Result Flag: critical\nMeasured Result: 6.4 g/dL",
        'resulted_at' => now()->subHours(14)->toDateTimeString(),
        'verified_at' => now()->subHours(13)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Critical hemoglobin reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'MED-IRON-FOLTAB',
        'medication_name' => 'Iron + Folic Acid',
        'dosage_instruction' => 'Take 1 tablet once daily',
        'clinical_indication' => 'Pregnancy anemia',
        'quantity_prescribed' => 30,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'MED-IRON-FOLTAB',
        'item_name' => 'Iron + Folic Acid',
        'unit' => 'tablet',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_severe_anemia_result_iron_folic_acid_review');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_severe_anemia_result_iron_folic_acid_review');
});

it('returns diclofenac renal review alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'RENAL:CREATININE',
        'test_name' => 'Serum Creatinine',
        'priority' => 'routine',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Renal review',
        'result_summary' => "Result Flag: abnormal\nMeasured Result: 182 umol/L",
        'resulted_at' => now()->subHours(14)->toDateTimeString(),
        'verified_at' => now()->subHours(13)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Renal result reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:M01AB05',
        'medication_name' => 'Diclofenac 50mg',
        'dosage_instruction' => 'Take 1 tablet every 8 hours for 3 days',
        'clinical_indication' => 'Pain',
        'quantity_prescribed' => 9,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:M01AB05',
        'item_name' => 'Diclofenac 50mg',
        'unit' => 'tablet',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_renal_risk_result_diclofenac_review');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_renal_risk_result_diclofenac_review');
});

it('returns nitrofurantoin low-clearance alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'RENAL:EGFR',
        'test_name' => 'eGFR',
        'priority' => 'routine',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Renal review',
        'result_summary' => "Result Flag: abnormal\nMeasured Result: 45 mL/min/1.73m2",
        'resulted_at' => now()->subHours(14)->toDateTimeString(),
        'verified_at' => now()->subHours(13)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Renal result reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:J01XE01',
        'medication_name' => 'Nitrofurantoin 100mg',
        'dosage_instruction' => 'Take 1 capsule twice daily for 5 days',
        'clinical_indication' => 'Urinary tract infection',
        'quantity_prescribed' => 10,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:J01XE01',
        'item_name' => 'Nitrofurantoin 100mg',
        'unit' => 'capsule',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_renal_risk_result_nitrofurantoin_low_clearance_review');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_renal_risk_result_nitrofurantoin_low_clearance_review');
});

it('returns metformin renal alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'RENAL:EGFR',
        'test_name' => 'eGFR',
        'priority' => 'routine',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Renal review',
        'result_summary' => "Result Flag: abnormal\nMeasured Result: 28 mL/min/1.73m2",
        'resulted_at' => now()->subHours(14)->toDateTimeString(),
        'verified_at' => now()->subHours(13)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Renal result reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:A10BA02',
        'medication_name' => 'Metformin 500mg',
        'dosage_instruction' => 'Take 1 tablet twice daily',
        'clinical_indication' => 'Diabetes',
        'quantity_prescribed' => 60,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:A10BA02',
        'item_name' => 'Metformin 500mg',
        'current_stock' => 50,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_renal_risk_result_metformin_contraindicated_range');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_renal_risk_result_metformin_contraindicated_range');
});

it('returns amoxicillin renal interval alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'RENAL:EGFR',
        'test_name' => 'eGFR',
        'priority' => 'routine',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Renal review',
        'result_summary' => "Result Flag: critical\nMeasured Result: 8 mL/min/1.73m2",
        'resulted_at' => now()->subHours(14)->toDateTimeString(),
        'verified_at' => now()->subHours(13)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Critical renal result reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:J01CA04',
        'medication_name' => 'Amoxicillin 500mg',
        'dosage_instruction' => 'Take 1 capsule every 8 hours for 5 days',
        'clinical_indication' => 'Respiratory infection',
        'quantity_prescribed' => 15,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:J01CA04',
        'item_name' => 'Amoxicillin 500mg',
        'unit' => 'capsule',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_renal_risk_result_amoxicillin_q24_review');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_renal_risk_result_amoxicillin_q24_review');
});

it('returns enalapril renal initial-dose review alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'RENAL:EGFR',
        'test_name' => 'eGFR',
        'priority' => 'routine',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Renal review',
        'result_summary' => "Result Flag: abnormal\nMeasured Result: 25 mL/min/1.73m2",
        'resulted_at' => now()->subHours(14)->toDateTimeString(),
        'verified_at' => now()->subHours(13)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Renal result reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:C09AA02',
        'medication_name' => 'Enalapril 5mg',
        'dosage_instruction' => 'Take 1 tablet once daily',
        'clinical_indication' => 'Hypertension',
        'quantity_prescribed' => 30,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:C09AA02',
        'item_name' => 'Enalapril 5mg',
        'unit' => 'tablet',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'warning')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_renal_risk_result_enalapril_initial_dose_review');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_renal_risk_result_enalapril_initial_dose_review');
});

it('returns spironolactone hyperkalemia alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'LOINC:2823-3',
        'test_name' => 'Serum Potassium',
        'priority' => 'routine',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Electrolyte review',
        'result_summary' => "Result Flag: critical\nMeasured Result: 6.1 mmol/L",
        'resulted_at' => now()->subHours(14)->toDateTimeString(),
        'verified_at' => now()->subHours(13)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'Critical potassium reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:C03DA01',
        'medication_name' => 'Spironolactone 25mg',
        'dosage_instruction' => 'Take 1 tablet once daily',
        'clinical_indication' => 'Heart failure',
        'quantity_prescribed' => 30,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:C03DA01',
        'item_name' => 'Spironolactone 25mg',
        'unit' => 'tablet',
        'current_stock' => 30,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
        ->assertOk()
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonCount(1, 'data.laboratorySignals');

    expect(collect($response->json('data.laboratorySignals'))->pluck('ruleCode')->all())
        ->toContain('recent_high_potassium_result_spironolactone_contraindicated_range');

    expect(collect($response->json('data.rules'))->pluck('code')->all())
        ->toContain('recent_high_potassium_result_spironolactone_contraindicated_range');
});

it('returns co-trimoxazole potassium and renal review alerts in pharmacy safety review', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subDay()->toDateTimeString(),
        'test_code' => 'LOINC:2823-3',
        'test_name' => 'Serum Potassium',
        'priority' => 'routine',
        'specimen_type' => 'blood',
        'clinical_notes' => 'Electrolyte review',
        'result_summary' => "Result Flag: high\nMeasured Result: 5.7 mmol/L",
        'resulted_at' => now()->subHours(14)->toDateTimeString(),
        'verified_at' => now()->subHours(13)->toDateTimeString(),
        'verified_by_user_id' => $user->id,
        'verification_note' => 'High potassium reviewed.',
        'status' => 'completed',
        'entry_state' => 'active',
    ]);

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
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

    $targetOrder = PharmacyOrderModel::query()->create([
        'order_number' => 'RX'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => null,
        'admission_id' => null,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:J01EE01',
        'medication_name' => 'Co-trimoxazole 960mg',
        'dosage_instruction' => 'Take 1 tablet every 12 hours',
        'clinical_indication' => 'Urinary tract infection',
        'quantity_prescribed' => 14,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'formulary_decision_status' => 'formulary',
        'status' => 'pending',
        'entry_state' => 'active',
        'status_reason' => null,
    ]);

    makePharmacyInventoryItem([
        'item_code' => 'ATC:J01EE01',
        'item_name' => 'Co-trimoxazole 960mg',
        'unit' => 'tablet',
        'current_stock' => 40,
        'reorder_level' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$targetOrder->id.'/safety-review')
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

it('updates pharmacy order fields', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'], [
            'dosageInstruction' => 'Take 2 tablets every 12 hours',
            'dispensingNotes' => 'Doctor updated dose',
        ])
        ->assertOk()
        ->assertJsonPath('data.dosageInstruction', 'Take 2 tablets every 12 hours');
});

it('signs pharmacy draft orders before workflow begins', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.entryState', 'draft')
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/sign')
        ->assertOk()
        ->assertJsonPath('data.entryState', 'active')
        ->assertJsonPath('data.signedByUserId', $user->id);

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/sign')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order']);
});

it('requires safety acknowledgement before signing a pharmacy draft with warning context', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient->id,
        'tenant_id' => null,
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dose' => '500 mg',
        'route' => 'oral',
        'frequency' => 'twice_daily',
        'source' => 'home_medication',
        'status' => 'active',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/sign')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['safetyAcknowledged']);

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/sign', [
            'safetyAcknowledged' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.entryState', 'active');
});

it('requires clinical indication before signing a pharmacy draft', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'entryMode' => 'draft',
            'clinicalIndication' => '',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/sign')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['clinicalIndication']);
});

it('requires override category and reason before signing a pharmacy draft with blocker context', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    PatientAllergyModel::query()->create([
        'patient_id' => $patient->id,
        'tenant_id' => null,
        'substance_code' => 'ATC:N02BE01',
        'substance_name' => 'Paracetamol 500mg',
        'reaction' => 'Anaphylaxis',
        'severity' => 'life_threatening',
        'status' => 'active',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/sign')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['safetyOverrideCode']);

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/sign', [
            'safetyOverrideCode' => 'benefit_outweighs_risk',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['safetyOverrideReason']);

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/sign', [
            'safetyOverrideCode' => 'benefit_outweighs_risk',
            'safetyOverrideReason' => 'Benefits outweigh the documented allergy while urgent clinician review continues.',
        ])
        ->assertOk()
        ->assertJsonPath('data.entryState', 'active');
});

it('discards pharmacy drafts before signing and blocks deleting signed orders', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $draft = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->deleteJson('/api/v1/pharmacy-orders/'.$draft['id'].'/draft')
        ->assertNoContent();

    $this->assertDatabaseMissing('pharmacy_orders', [
        'id' => $draft['id'],
    ]);

    $signed = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$signed['id'].'/sign')
        ->assertOk();

    $this->actingAs($user)
        ->deleteJson('/api/v1/pharmacy-orders/'.$signed['id'].'/draft')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order']);
});

it('blocks pharmacy status workflow while order remains draft', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order']);
});

it('rejects empty pharmacy order patch payload', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'], [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payload']);
});

it('updates pharmacy order from preparation to dispensed and sets dispensed timestamp', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $inventoryItem = makePharmacyInventoryItem();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'medicationCode' => 'ATC:N02BE01',
            'medicationName' => 'Paracetamol 500mg',
            'quantityPrescribed' => 12,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Reviewed and queued for release.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_preparation');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'dispensingNotes' => 'Dispensed full quantity',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'dispensed');

    $record = PharmacyOrderModel::query()->find($created['id']);
    $inventoryItem->refresh();
    $movement = InventoryStockMovementModel::query()->latest('created_at')->first();
    expect($record?->dispensed_at)->not->toBeNull();
    expect((float) $inventoryItem->current_stock)->toBe(468.0);
    expect($movement?->movement_type)->toBe('issue');
    expect((float) ($movement?->quantity ?? 0))->toBe(12.0);
    expect($movement?->item_id)->toBe($inventoryItem->id);
});

it('uses FEFO batch stock when dispensing a pharmacy order', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $inventoryItem = makePharmacyInventoryItem([
        'item_code' => 'ATC:N02BE01',
        'item_name' => 'Paracetamol 500mg',
        'current_stock' => 20,
        'reorder_level' => 4,
    ]);
    $earliestBatch = inventoryBatchRecord($inventoryItem->id, [
        'batch_number' => 'PHA-FEFO-001',
        'expiry_date' => now()->addDays(10)->toDateString(),
        'quantity' => 2,
    ]);
    $laterBatch = inventoryBatchRecord($inventoryItem->id, [
        'batch_number' => 'PHA-FEFO-002',
        'expiry_date' => now()->addDays(60)->toDateString(),
        'quantity' => 8,
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'medicationCode' => 'ATC:N02BE01',
            'medicationName' => 'Paracetamol 500mg',
            'quantityPrescribed' => 5,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Prepared FEFO release.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'dispensingNotes' => 'Dispensed with FEFO batch selection.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'dispensed');

    $inventoryItem->refresh();
    expect((float) $inventoryItem->current_stock)->toBe(15.0);
    expect((float) DB::table('inventory_batches')->where('id', $earliestBatch['id'])->value('quantity'))->toBe(0.0);
    expect((float) DB::table('inventory_batches')->where('id', $laterBatch['id'])->value('quantity'))->toBe(5.0);

    $movement = InventoryStockMovementModel::query()->latest('created_at')->first();
    expect($movement)->not->toBeNull();
    expect($movement?->metadata['batchMode'] ?? null)->toBe('tracked');
    expect($movement?->metadata['batchAllocationCount'] ?? null)->toBe(2);
    expect($movement?->metadata['batchAllocations'][0]['batchId'] ?? null)->toBe($earliestBatch['id']);
    expect((float) ($movement?->metadata['batchAllocations'][0]['quantity'] ?? 0))->toBe(2.0);
    expect($movement?->metadata['batchAllocations'][1]['batchId'] ?? null)->toBe($laterBatch['id']);
    expect((float) ($movement?->metadata['batchAllocations'][1]['quantity'] ?? 0))->toBe(3.0);
});

it('issues only the remaining inventory delta when pharmacy order moves from partial to final dispense', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $inventoryItem = makePharmacyInventoryItem([
        'item_code' => 'ATC:J01CA04',
        'item_name' => 'Amoxicillin 500mg',
        'unit' => 'capsule',
        'category' => 'antibiotics',
        'current_stock' => 100,
        'reorder_level' => 25,
        'max_stock_level' => 200,
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'medicationCode' => 'ATC:J01CA04',
            'medicationName' => 'Amoxicillin 500mg',
            'quantityPrescribed' => 12,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Prepared first fill.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'partially_dispensed',
            'quantityDispensed' => 5,
            'dispensingNotes' => 'Released first fill.',
        ])
        ->assertOk()
        ->assertJsonPath('data.quantityDispensed', '5.00');

    $inventoryItem->refresh();
    expect((float) $inventoryItem->current_stock)->toBe(95.0);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'quantityDispensed' => 12,
            'dispensingNotes' => 'Released remaining balance.',
        ])
        ->assertOk()
        ->assertJsonPath('data.quantityDispensed', '12.00');

    $inventoryItem->refresh();
    $movements = InventoryStockMovementModel::query()
        ->where('item_id', $inventoryItem->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect((float) $inventoryItem->current_stock)->toBe(88.0);
    expect($movements)->toHaveCount(2);
    expect($movements->map(fn (InventoryStockMovementModel $movement): float => (float) $movement->quantity)->all())
        ->toEqual([5.0, 7.0]);
});

it('issues stock for the substituted medicine instead of the originally ordered medicine', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $orderedInventoryItem = makePharmacyInventoryItem([
        'item_code' => 'MED-PARA-500TAB',
        'item_name' => 'Paracetamol 500mg',
        'current_stock' => 50,
    ]);
    $substituteInventoryItem = makePharmacyInventoryItem([
        'item_code' => 'ATC:M01AE01',
        'item_name' => 'Ibuprofen 400mg',
        'current_stock' => 20,
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'medicationCode' => 'ATC:N02BE01',
            'medicationName' => 'Paracetamol 500mg',
            'quantityPrescribed' => 8,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/policy', [
            'formularyDecisionStatus' => 'non_formulary',
            'formularyDecisionReason' => 'Preferred item unavailable.',
            'substitutionAllowed' => true,
            'substitutionMade' => true,
            'substitutedMedicationCode' => 'ATC:M01AE01',
            'substitutedMedicationName' => 'Ibuprofen 400mg',
            'substitutionReason' => 'Equivalent analgesic selected.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Reviewed substitution release.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'dispensingNotes' => 'Dispensed substitute release.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'dispensed');

    $orderedInventoryItem->refresh();
    $substituteInventoryItem->refresh();
    $movement = InventoryStockMovementModel::query()->latest('created_at')->first();

    expect((float) $orderedInventoryItem->current_stock)->toBe(50.0);
    expect((float) $substituteInventoryItem->current_stock)->toBe(12.0);
    expect($movement?->item_id)->toBe($substituteInventoryItem->id);
});

it('rejects dispense when inventory stock would go negative and leaves the order unchanged', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $inventoryItem = makePharmacyInventoryItem([
        'item_code' => 'MED-PARA-500TAB',
        'item_name' => 'Paracetamol 500mg',
        'current_stock' => 3,
        'reorder_level' => 5,
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'medicationCode' => 'ATC:N02BE01',
            'medicationName' => 'Paracetamol 500mg',
            'quantityPrescribed' => 12,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Prepared against limited stock.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'dispensingNotes' => 'Attempt dispense with low stock.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['quantityDispensed']);

    $inventoryItem->refresh();
    $record = PharmacyOrderModel::query()->find($created['id']);

    expect((float) $inventoryItem->current_stock)->toBe(3.0);
    expect($record?->status)->toBe('in_preparation');
    expect($record?->dispensed_at)->toBeNull();
});

it('verifies dispensed pharmacy order and stores verification metadata', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    makePharmacyInventoryItem([
        'item_code' => 'ATC:N02BE01',
        'item_name' => 'Paracetamol 500mg',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'quantityPrescribed' => 12,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Prepared for release verification.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'dispensingNotes' => 'Dispensed full quantity',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Pharmacist release check completed.',
        ])
        ->assertOk()
        ->assertJsonPath('data.verifiedByUserId', $user->id)
        ->assertJsonPath('data.verificationNote', 'Pharmacist release check completed.');

    $record = PharmacyOrderModel::query()->find($created['id']);
    expect($record?->verified_at)->not->toBeNull();
    expect($record?->verified_by_user_id)->toBe($user->id);
    expect($record?->verification_note)->toBe('Pharmacist release check completed.');
});

it('requires verification note when verifying dispensed substitution release', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    makePharmacyInventoryItem([
        'item_code' => 'ATC:N02BE01',
        'item_name' => 'Paracetamol 500mg',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'quantityPrescribed' => 12,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Prepared substituted release.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'dispensingNotes' => "Dispense verification\nSubstitution: yes (generic brand unavailable)",
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/verify', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['verification']);
});

it('updates pharmacy policy review and substitution metadata', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/policy', [
            'formularyDecisionStatus' => 'non_formulary',
            'formularyDecisionReason' => 'Formulary alternative is out of stock.',
            'substitutionAllowed' => true,
            'substitutionMade' => true,
            'substitutedMedicationCode' => 'ATC:N02BE99',
            'substitutedMedicationName' => 'Paracetamol 650mg',
            'substitutionReason' => 'Equivalent strength available in stock.',
        ])
        ->assertOk()
        ->assertJsonPath('data.formularyDecisionStatus', 'non_formulary')
        ->assertJsonPath('data.substitutionMade', true)
        ->assertJsonPath('data.substitutedMedicationCode', 'ATC:N02BE99');
});

it('rejects pharmacy policy update when substitution is made but not allowed', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/policy', [
            'formularyDecisionStatus' => 'formulary',
            'substitutionAllowed' => false,
            'substitutionMade' => true,
            'substitutedMedicationCode' => 'ATC:N02BE99',
            'substitutedMedicationName' => 'Paracetamol 650mg',
            'substitutionReason' => 'Policy mismatch',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['policy']);
});

it('requires a reason when approving restricted medicine with unclear indication', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $restrictedCatalogItem = ensureActiveApprovedMedicineCatalogItem([
        'code' => 'ATC:J01DD04',
        'name' => 'Ceftriaxone 1g',
        'category' => 'antibiotics',
        'unit' => 'vial',
        'description' => 'Restricted injectable antibiotic fixture',
        'metadata' => [
            'reviewMode' => 'policy_review_required',
            'restrictionReason' => 'Broad-spectrum injectable antibiotic. Review indication and release path before dispensing.',
            'allowedIndicationKeywords' => ['severe infection', 'sepsis'],
        ],
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id, [
                'approvedMedicineCatalogItemId' => $restrictedCatalogItem->id,
                'clinicalIndication' => 'Unclear infection',
                'dosageInstruction' => 'Give 1 vial every 24 hours',
            ]),
            ['safetyAcknowledged' => true],
        ))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/policy', [
            'formularyDecisionStatus' => 'formulary',
            'substitutionAllowed' => false,
            'substitutionMade' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['formularyDecisionReason']);
});

it('rejects pharmacy policy update when the substitute matches the original medicine', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/policy', [
            'formularyDecisionStatus' => 'non_formulary',
            'formularyDecisionReason' => 'Exception review required.',
            'substitutionAllowed' => true,
            'substitutionMade' => true,
            'substitutedMedicationCode' => 'ATC:N02BE01',
            'substitutedMedicationName' => 'Paracetamol 500mg',
            'substitutionReason' => 'Accidental duplicate selection.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['substitutedMedicationCode']);
});

it('updates pharmacy medication reconciliation after verify', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $created = createVerifiedDispensedPharmacyOrder($user, $patient->id, [
        'quantityPrescribed' => 12,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/reconciliation', [
            'reconciliationStatus' => 'completed',
            'reconciliationDecision' => 'short_course_only',
            'reconciliationNote' => 'Medication reconciliation completed.',
        ])
        ->assertOk()
        ->assertJsonPath('data.reconciliationStatus', 'completed')
        ->assertJsonPath('data.reconciliationDecision', 'short_course_only')
        ->assertJsonPath('data.reconciledByUserId', $user->id);
});

it('requires a structured reconciliation decision when completing pharmacy reconciliation', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $created = createVerifiedDispensedPharmacyOrder($user, $patient->id, [
        'quantityPrescribed' => 12,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/reconciliation', [
            'reconciliationStatus' => 'completed',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reconciliationDecision']);
});

it('adds dispensed medicine to current medications during pharmacy reconciliation', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    $created = createVerifiedDispensedPharmacyOrder($user, $patient->id, [
        'medicationCode' => 'ATC:J01CA04',
        'medicationName' => 'Amoxicillin 500mg',
        'dosageInstruction' => 'Take 1 capsule every 8 hours for 5 days',
        'quantityPrescribed' => 15,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/reconciliation', [
            'reconciliationStatus' => 'completed',
            'reconciliationDecision' => 'add_to_current_list',
            'reconciliationNote' => 'Continue treatment after discharge.',
        ])
        ->assertOk()
        ->assertJsonPath('data.reconciliationDecision', 'add_to_current_list');

    $profile = PatientMedicationProfileModel::query()
        ->where('patient_id', $patient->id)
        ->where('medication_code', 'ATC:J01CA04')
        ->latest('created_at')
        ->first();

    expect($profile)->not->toBeNull();
    expect($profile?->status)->toBe('active');
    expect($profile?->medication_name)->toBe('Amoxicillin 500mg');
    expect($profile?->dose)->toBe('Take 1 capsule every 8 hours for 5 days');
    expect($profile?->last_reconciled_at)->not->toBeNull();
    expect((string) $profile?->reconciliation_note)->toContain('Continue treatment after discharge.');
});

it('stops matching current medications during pharmacy reconciliation', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $existingProfile = PatientMedicationProfileModel::query()->create([
        'patient_id' => $patient->id,
        'tenant_id' => null,
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dose' => 'Take 1 tablet every 8 hours after meals',
        'source' => 'home_medication',
        'status' => 'active',
        'started_at' => now()->subDays(10),
    ]);

    $created = createVerifiedDispensedPharmacyOrder($user, $patient->id, [
        'quantityPrescribed' => 12,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/reconciliation', [
            'reconciliationStatus' => 'completed',
            'reconciliationDecision' => 'stop_from_current_list',
            'reconciliationNote' => 'Short-term analgesic completed.',
        ])
        ->assertOk()
        ->assertJsonPath('data.reconciliationDecision', 'stop_from_current_list');

    $existingProfile->refresh();

    expect($existingProfile->status)->toBe('stopped');
    expect($existingProfile->stopped_at)->not->toBeNull();
    expect($existingProfile->last_reconciled_at)->not->toBeNull();
    expect((string) $existingProfile->reconciliation_note)->toContain(
        'Short-term analgesic completed.',
    );
});

it('rejects pharmacy reconciliation before dispense verification', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    makePharmacyInventoryItem([
        'item_code' => 'ATC:N02BE01',
        'item_name' => 'Paracetamol 500mg',
    ]);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'quantityPrescribed' => 12,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Prepared but not yet verified.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'dispensingNotes' => 'Dispensed full quantity',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/reconciliation', [
            'reconciliationStatus' => 'completed',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reconciliation']);
});

it('rejects pharmacy dispense verification when order is not dispensed', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Should fail before dispense.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['verification']);
});

it('rejects direct pharmacy dispense before preparation starts', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    makePharmacyInventoryItem();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'quantityPrescribed' => 12,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'dispensingNotes' => 'Attempted direct release.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    $record = PharmacyOrderModel::query()->find($created['id']);
    expect($record?->status)->toBe('pending');
    expect($record?->dispensed_at)->toBeNull();
});

it('rejects pharmacy partial dispense before preparation starts', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    makePharmacyInventoryItem();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'quantityPrescribed' => 12,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'partially_dispensed',
            'quantityDispensed' => 4,
            'dispensingNotes' => 'Attempted direct partial release.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('rejects pharmacy partial dispense when quantity reaches full prescribed amount', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    makePharmacyInventoryItem();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'quantityPrescribed' => 12,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Ready for issue.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'partially_dispensed',
            'quantityDispensed' => 12,
            'dispensingNotes' => 'Tried to use partial for full release.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['quantityDispensed']);
});

it('rejects final pharmacy dispense when quantity remains below prescribed amount', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    makePharmacyInventoryItem();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'quantityPrescribed' => 12,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Ready for partial issue.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'quantityDispensed' => 8,
            'dispensingNotes' => 'Attempted final release below full amount.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['quantityDispensed']);
});

it('enforces reason on cancelled pharmacy order status', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'cancelled',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('writes pharmacy order audit logs for create update and status change', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/pharmacy-orders/'.$created['id'], [
        'dispensingNotes' => 'audit update check',
    ])->assertOk();

    $this->actingAs($user)->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/sign')
        ->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
        'status' => 'in_preparation',
        'dispensingNotes' => 'preparing meds',
    ])->assertOk();

    $logs = PharmacyOrderAuditLogModel::query()
        ->where('pharmacy_order_id', $created['id'])
        ->orderBy('created_at')
        ->get();

    expect($logs)->toHaveCount(4);
    expect($logs->pluck('action')->all())->toContain(
        'pharmacy-order.created',
        'pharmacy-order.updated',
        'pharmacy-order.signed',
        'pharmacy-order.status.updated',
    );
    expect($logs->first()->actor_id)->toBe($user->id);
});

it('lists pharmacy order audit logs when authorized', function (): void {
    $user = makePharmacyUser();
    $user->givePermissionTo('pharmacy-orders.view-audit-logs');
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/pharmacy-orders/'.$created['id'], [
        'dispensingNotes' => 'Audit pagination check',
    ])->assertOk();

    $this->actingAs($user)->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/sign')
        ->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
        'status' => 'cancelled',
        'reason' => 'Out of stock',
    ])->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs?perPage=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('data.0.action', 'pharmacy-order.status.updated')
        ->assertJsonPath('data.1.action', 'pharmacy-order.signed');
});

it('filters pharmacy order audit logs by action text actor type and actor id', function (): void {
    $user = makePharmacyUser();
    $user->givePermissionTo('pharmacy-orders.view-audit-logs');
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/pharmacy-orders/'.$created['id'], [
        'dispensingNotes' => 'audit filter update',
    ])->assertOk();

    $this->actingAs($user)->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/sign')
        ->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
        'status' => 'cancelled',
        'reason' => 'audit filter check',
    ])->assertOk();

    PharmacyOrderAuditLogModel::query()->create([
        'pharmacy_order_id' => $created['id'],
        'action' => 'pharmacy-order.system.reconciled',
        'actor_id' => null,
        'changes' => [],
        'metadata' => ['source' => 'system-job'],
        'created_at' => now()->addSecond(),
    ]);

    $this->actingAs($user)
        ->getJson(
            '/api/v1/pharmacy-orders/'.$created['id']
                .'/audit-logs?actorType=user&actorId='.$user->id
                .'&action=pharmacy-order.status.updated&q=STATUS',
        )
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'pharmacy-order.status.updated')
        ->assertJsonPath('data.0.actorId', $user->id);

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs?actorType=system&q=RECONCILED')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'pharmacy-order.system.reconciled')
        ->assertJsonPath('data.0.actorId', null);
});

it('exports pharmacy order audit logs as csv when authorized and applies filters', function (): void {
    $user = makePharmacyUser();
    $user->givePermissionTo('pharmacy-orders.view-audit-logs');
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'entryMode' => 'draft',
        ]))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/pharmacy-orders/'.$created['id'], [
        'dispensingNotes' => 'audit export update',
    ])->assertOk();

    $this->actingAs($user)->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/sign')
        ->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
        'status' => 'cancelled',
        'reason' => 'audit export check',
    ])->assertOk();

    PharmacyOrderAuditLogModel::query()->create([
        'pharmacy_order_id' => $created['id'],
        'action' => 'pharmacy-order.system.reconciled',
        'actor_id' => null,
        'changes' => [],
        'metadata' => ['source' => 'system-job'],
        'created_at' => now()->addSecond(),
    ]);

    $response = $this->actingAs($user)
        ->get('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs/export?actorType=system&q=RECONCILED');

    $response->assertOk();
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    $response->assertHeader('X-Export-System-Name', 'Afyanova AHS');
    $response->assertHeader('X-Export-System-Slug', 'afyanova_ahs');
    expect((string) $response->headers->get('content-type'))->toContain('text/csv');
    expect((string) $response->headers->get('content-disposition'))->toContain('afyanova_ahs_pharmacy_audit_');
    expect($response->streamedContent())->toContain('createdAt,action,actorType,actorId,changes,metadata');
    expect($response->streamedContent())->toContain('pharmacy-order.system.reconciled');
});

it('creates pharmacy order audit log csv export job when authorized', function (): void {
    Queue::fake();

    $user = makePharmacyUser();
    $user->givePermissionTo('pharmacy-orders.view-audit-logs');
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs/export-jobs', [
            'actorType' => 'system',
            'q' => 'reconciled',
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.schemaVersion', 'audit-log-csv.v1')
        ->assertJsonPath('data.downloadUrl', null);

    $jobId = (string) $response->json('data.id');
    $job = AuditExportJobModel::query()->findOrFail($jobId);

    expect($job->module)->toBe(GenerateAuditExportCsvJob::MODULE_PHARMACY);
    expect($job->target_resource_id)->toBe($created['id']);
    expect($job->created_by_user_id)->toBe($user->id);
    expect($job->status)->toBe('queued');
    expect($job->filters)->toMatchArray([
        'q' => 'reconciled',
        'action' => null,
        'actorType' => 'system',
        'actorId' => null,
        'from' => null,
        'to' => null,
    ]);

    Queue::assertPushed(GenerateAuditExportCsvJob::class, 1);
});

it('shows pharmacy order audit log csv export job status for creator', function (): void {
    $user = makePharmacyUser();
    $user->givePermissionTo('pharmacy-orders.view-audit-logs');
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $job = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_PHARMACY,
        'target_resource_id' => $created['id'],
        'status' => 'completed',
        'row_count' => 4,
        'file_path' => 'audit-exports/test-pharmacy-status.csv',
        'file_name' => 'pharmacy_status.csv',
        'created_by_user_id' => $user->id,
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs/export-jobs/'.$job->id)
        ->assertOk()
        ->assertJsonPath('data.id', (string) $job->id)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.rowCount', 4)
        ->assertJsonPath('data.schemaVersion', 'audit-log-csv.v1')
        ->assertJsonPath(
            'data.downloadUrl',
            '/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs/export-jobs/'.$job->id.'/download',
        );
});

it('downloads completed pharmacy order audit log csv export job', function (): void {
    Storage::fake('local');

    $user = makePharmacyUser();
    $user->givePermissionTo('pharmacy-orders.view-audit-logs');
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $filePath = 'audit-exports/test-pharmacy-download.csv';
    Storage::disk('local')->put($filePath, "createdAt,action,actorType,actorId,changes,metadata\n");

    $job = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_PHARMACY,
        'target_resource_id' => $created['id'],
        'status' => 'completed',
        'row_count' => 1,
        'file_path' => $filePath,
        'file_name' => 'pharmacy_download.csv',
        'created_by_user_id' => $user->id,
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs/export-jobs/'.$job->id.'/download');

    $response->assertOk();
    $response->assertDownload('pharmacy_download.csv');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    $response->assertHeader('X-Export-System-Name', 'Afyanova AHS');
    $response->assertHeader('X-Export-System-Slug', 'afyanova_ahs');
    expect((string) $response->headers->get('content-type'))->toContain('text/csv');
});

it('returns 409 when pharmacy order audit log csv export job is not ready', function (): void {
    $user = makePharmacyUser();
    $user->givePermissionTo('pharmacy-orders.view-audit-logs');
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $job = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_PHARMACY,
        'target_resource_id' => $created['id'],
        'status' => 'queued',
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs/export-jobs/'.$job->id.'/download')
        ->assertStatus(409)
        ->assertJsonPath('code', 'EXPORT_JOB_NOT_READY');
});

it('lists pharmacy order audit log csv export jobs for creator only', function (): void {
    $user = makePharmacyUser();
    $user->givePermissionTo('pharmacy-orders.view-audit-logs');
    $otherUser = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $jobOne = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_PHARMACY,
        'target_resource_id' => $created['id'],
        'status' => 'failed',
        'filters' => ['actorType' => 'system'],
        'created_by_user_id' => $user->id,
        'error_message' => 'test failure',
    ]);
    $jobTwo = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_PHARMACY,
        'target_resource_id' => $created['id'],
        'status' => 'completed',
        'row_count' => 2,
        'file_path' => 'audit-exports/test-pharmacy-history.csv',
        'file_name' => 'pharmacy_history.csv',
        'created_by_user_id' => $user->id,
        'completed_at' => now(),
    ]);
    AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_PHARMACY,
        'target_resource_id' => $created['id'],
        'status' => 'queued',
        'created_by_user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs/export-jobs?perPage=10');

    $response->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.perPage', 10);

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain((string) $jobOne->id, (string) $jobTwo->id);
});

it('retries pharmacy order audit log csv export job when authorized', function (): void {
    Queue::fake();

    $user = makePharmacyUser();
    $user->givePermissionTo('pharmacy-orders.view-audit-logs');
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $sourceJob = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_PHARMACY,
        'target_resource_id' => $created['id'],
        'status' => 'failed',
        'filters' => [
            'q' => 'reconciled',
            'action' => null,
            'actorType' => 'system',
            'actorId' => null,
            'from' => null,
            'to' => null,
        ],
        'created_by_user_id' => $user->id,
        'error_message' => 'test failure',
        'failed_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs/export-jobs/'.$sourceJob->id.'/retry');

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.downloadUrl', null);

    $retryJobId = (string) $response->json('data.id');
    expect($retryJobId)->not()->toBe((string) $sourceJob->id);

    $retryJob = AuditExportJobModel::query()->findOrFail($retryJobId);
    expect($retryJob->module)->toBe(GenerateAuditExportCsvJob::MODULE_PHARMACY);
    expect($retryJob->target_resource_id)->toBe($created['id']);
    expect($retryJob->created_by_user_id)->toBe($user->id);
    expect($retryJob->filters)->toMatchArray($sourceJob->filters ?? []);

    Queue::assertPushed(GenerateAuditExportCsvJob::class, 1);
});

it('forbids pharmacy order audit log access without permission', function (): void {
    $creator = makePharmacyUser();
    $reader = makePharmacyReadOnlyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($reader)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('forbids pharmacy order audit log csv export job retry without permission', function (): void {
    Queue::fake();

    $creator = makePharmacyUser();
    $reader = makePharmacyReadOnlyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $sourceJob = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_PHARMACY,
        'target_resource_id' => $created['id'],
        'status' => 'failed',
        'created_by_user_id' => $creator->id,
    ]);

    $this->actingAs($reader)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs/export-jobs/'.$sourceJob->id.'/retry')
        ->assertForbidden();
});

it('forbids pharmacy order audit log csv export job create without permission', function (): void {
    Queue::fake();

    $creator = makePharmacyUser();
    $reader = makePharmacyReadOnlyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($reader)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs/export-jobs')
        ->assertForbidden();
});

it('forbids pharmacy order audit log csv export access without permission', function (): void {
    $creator = makePharmacyUser();
    $reader = makePharmacyReadOnlyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($reader)
        ->get('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs/export')
        ->assertForbidden();
});

it('forbids pharmacy order audit logs when gate override denies', function (): void {
    Gate::define('pharmacy-orders.view-audit-logs', static fn (): bool => false);

    $user = makePharmacyUser();
    $user->givePermissionTo('pharmacy-orders.view-audit-logs');
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('returns 404 for pharmacy order audit logs of unknown id', function (): void {
    $user = makePharmacyUser();
    $user->givePermissionTo('pharmacy-orders.view-audit-logs');

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/060afc03-2ce9-4b1d-a1c2-326d2722ce25/audit-logs')
        ->assertNotFound();
});

it('lists and filters pharmacy orders', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    PharmacyOrderModel::query()->create([
        'order_number' => 'RX20260225AAAAAA',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => 'TID',
        'quantity_prescribed' => 12,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'dispensed_at' => null,
        'status' => 'pending',
        'status_reason' => null,
    ]);

    PharmacyOrderModel::query()->create([
        'order_number' => 'RX20260225BBBBBB',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHours(5)->toDateTimeString(),
        'medication_code' => 'ATC:J01CA04',
        'medication_name' => 'Amoxicillin 500mg',
        'dosage_instruction' => 'BID',
        'quantity_prescribed' => 14,
        'quantity_dispensed' => 14,
        'dispensing_notes' => null,
        'dispensed_at' => now()->subHours(4)->toDateTimeString(),
        'status' => 'dispensed',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders?q=Paracetamol&status=pending')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.medicationName', 'Paracetamol 500mg')
        ->assertJsonPath('data.0.status', 'pending');
});

it('exposes current care flags for dispensed pharmacy orders awaiting follow-up', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();
    makePharmacyInventoryItem();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id),
            ['safetyAcknowledged' => true],
        ))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Prepared for dispensing.',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$created['id'].'/status', [
            'status' => 'dispensed',
            'dispensingNotes' => 'Dispensed full quantity.',
        ])
        ->assertOk()
        ->assertJsonPath('data.currentCare.isCurrent', true)
        ->assertJsonPath('data.currentCare.requiresReview', true)
        ->assertJsonPath('data.currentCare.awaitingVerification', true)
        ->assertJsonPath('data.currentCare.awaitingReconciliation', true)
        ->assertJsonPath('data.currentCare.priorityRank', 540)
        ->assertJsonPath('data.currentCare.workflowHint', 'Dispense verification is still required before pharmacy work can close.')
        ->assertJsonPath('data.currentCare.nextAction.key', 'verify_dispense')
        ->assertJsonPath('data.currentCare.nextAction.label', 'Verify dispense')
        ->assertJsonPath('data.currentCare.nextAction.emphasis', 'primary');
});

it('stamps pharmacy order tenant and facility scope when created under resolved platform scope', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    [$tenantId, $facilityId] = seedPharmacyPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-PHA',
        facilityName: 'Dar Pharmacy',
    );

    $created = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZH',
            'X-Facility-Code' => 'DAR-PHA',
        ])
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $row = PharmacyOrderModel::query()->findOrFail($created['id']);

    expect($row->tenant_id)->toBe($tenantId);
    expect($row->facility_id)->toBe($facilityId);
});

it('filters pharmacy order reads by facility scope when platform multi facility scoping is enabled', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', true);

    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    [$tenantId, $facilityId] = seedPharmacyPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-PHA',
        facilityName: 'Nairobi Pharmacy',
    );

    [, $otherFacilityId] = seedPharmacyPlatformScopeFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-PHA',
        facilityName: 'Mombasa Pharmacy',
    );

    $visible = PharmacyOrderModel::query()->create([
        'order_number' => 'RX20260225SCOPP1',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHour()->toDateTimeString(),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Scoped Visible Paracetamol',
        'dosage_instruction' => 'TID',
        'quantity_prescribed' => 12,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'dispensed_at' => null,
        'status' => 'pending',
        'status_reason' => null,
    ]);

    $hidden = PharmacyOrderModel::query()->create([
        'order_number' => 'RX20260225SCOPP2',
        'tenant_id' => $tenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'medication_code' => 'ATC:J01CA04',
        'medication_name' => 'Scoped Hidden Amoxicillin',
        'dosage_instruction' => 'BID',
        'quantity_prescribed' => 14,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'dispensed_at' => null,
        'status' => 'pending',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-PHA',
        ])
        ->getJson('/api/v1/pharmacy-orders')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.medicationName', 'Scoped Visible Paracetamol');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-PHA',
        ])
        ->getJson('/api/v1/pharmacy-orders/'.$hidden->id)
        ->assertNotFound();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-PHA',
        ])
        ->patchJson('/api/v1/pharmacy-orders/'.$hidden->id, [
            'dispensingNotes' => 'Attempted cross-facility update',
        ])
        ->assertNotFound();
});

it('filters pharmacy order reads by facility scope when enabled via feature flag override', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', false);

    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    [$tenantId, $facilityId] = seedPharmacyPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-PHA',
        facilityName: 'Nairobi Pharmacy',
    );

    [, $otherFacilityId] = seedPharmacyPlatformScopeFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-PHA',
        facilityName: 'Mombasa Pharmacy',
    );

    DB::table('feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_name' => 'platform.multi_facility_scoping',
        'scope_type' => 'country',
        'scope_key' => 'KE',
        'enabled' => true,
        'reason' => 'enable scoping for Kenya pharmacy rollout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $visible = PharmacyOrderModel::query()->create([
        'order_number' => 'RX20260225SCOPP3',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHour()->toDateTimeString(),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Override Visible Paracetamol',
        'dosage_instruction' => 'TID',
        'quantity_prescribed' => 12,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'dispensed_at' => null,
        'status' => 'pending',
        'status_reason' => null,
    ]);

    PharmacyOrderModel::query()->create([
        'order_number' => 'RX20260225SCOPP4',
        'tenant_id' => $tenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'medication_code' => 'ATC:J01CA04',
        'medication_name' => 'Override Hidden Amoxicillin',
        'dosage_instruction' => 'BID',
        'quantity_prescribed' => 14,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'dispensed_at' => null,
        'status' => 'pending',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-PHA',
        ])
        ->getJson('/api/v1/pharmacy-orders')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.medicationName', 'Override Visible Paracetamol');
});

it('blocks pharmacy order creation in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id))
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks pharmacy order update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $order = PharmacyOrderModel::query()->create([
        'order_number' => 'RX20260225GUARDP1',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Guard Update Paracetamol',
        'dosage_instruction' => 'TID',
        'quantity_prescribed' => 12,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'dispensed_at' => null,
        'status' => 'pending',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order->id, [
            'dispensingNotes' => 'Attempted guarded update',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks pharmacy order status update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $order = PharmacyOrderModel::query()->create([
        'order_number' => 'RX20260225GUARDP2',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Guard Status Paracetamol',
        'dosage_instruction' => 'TID',
        'quantity_prescribed' => 12,
        'quantity_dispensed' => 0,
        'dispensing_notes' => null,
        'dispensed_at' => null,
        'status' => 'pending',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order->id.'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Attempted guarded status update',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('applies pharmacy discontinue lifecycle action and records audit metadata', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'quantityDispensed' => 5,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/lifecycle', [
            'action' => 'discontinue',
            'reason' => 'Therapy changed after review.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.lifecycleReasonCode', 'discontinued')
        ->assertJsonPath('data.statusReason', 'Therapy changed after review.');

    $row = PharmacyOrderModel::query()->findOrFail($created['id']);
    expect($row->status)->toBe('cancelled');
    expect($row->lifecycle_reason_code)->toBe('discontinued');

    $audit = PharmacyOrderAuditLogModel::query()
        ->where('pharmacy_order_id', $created['id'])
        ->where('action', 'pharmacy-order.lifecycle.discontinued')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit?->metadata['lifecycle_action'] ?? null)->toBe('discontinue');
});

it('rejects pharmacy cancel lifecycle action when dispensed quantity exists', function (): void {
    $user = makePharmacyUser();
    $patient = makePharmacyPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', pharmacyOrderPayload($patient->id, [
            'quantityDispensed' => 3,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders/'.$created['id'].'/lifecycle', [
            'action' => 'cancel',
            'reason' => 'Cancelled by prescriber.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['action']);
});

it('reports raw on-hand stock as available when no reservation exists', function (): void {
    $user = makePharmacyUser();
    makePharmacyInventoryItem();

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/availability?'.http_build_query([
            'medicationCode' => 'ATC:N02BE01',
            'medicationName' => 'Paracetamol 500mg',
        ]))
        ->assertOk()
        ->assertJsonPath('data.itemCode', 'ATC:N02BE01')
        ->assertJsonPath('data.currentStock', 480)
        ->assertJsonPath('data.onHandStock', '480.000')
        ->assertJsonPath('data.stockState', 'healthy');
});

it('reduces available stock by active reservations without changing on-hand stock', function (): void {
    $user = makePharmacyUser();
    $item = makePharmacyInventoryItem();

    [$tenantId, $facilityId] = seedPharmacyPlatformScopeFacility(
        tenantCode: 'AVAIL-TEST-TENANT',
        tenantName: 'Availability Test Tenant',
        countryCode: 'KE',
        facilityCode: 'AVAIL-TEST-FACILITY',
        facilityName: 'Availability Test Facility',
    );

    DB::table('inventory_stock_reservations')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'item_id' => $item->id,
        'batch_id' => null,
        'warehouse_id' => null,
        'source_type' => 'pharmacy_order',
        'source_id' => (string) Str::uuid(),
        'source_line_id' => null,
        'quantity' => 150,
        'status' => 'active',
        'reserved_by_user_id' => $user->id,
        'reserved_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/availability?'.http_build_query([
            'medicationCode' => 'ATC:N02BE01',
            'medicationName' => 'Paracetamol 500mg',
        ]))
        ->assertOk()
        ->assertJsonPath('data.currentStock', 330)
        ->assertJsonPath('data.onHandStock', '480.000');
});

it('reports no active inventory match for an unknown medication', function (): void {
    $user = makePharmacyUser();

    $this->actingAs($user)
        ->getJson('/api/v1/pharmacy-orders/availability?'.http_build_query([
            'medicationCode' => 'NOT-A-REAL-CODE',
            'medicationName' => 'Nonexistent Medicine',
        ]))
        ->assertOk()
        ->assertJsonPath('data', null);
});

/**
 * @return array{0:string,1:string}
 */
function seedPharmacyPlatformScopeAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedPharmacyPlatformScopeFacility(
        tenantCode: $tenantCode,
        tenantName: $tenantName,
        countryCode: $countryCode,
        facilityCode: $facilityCode,
        facilityName: $facilityName,
    );

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'pharmacist',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}

it('accepts an IV fluid order dosed by volume instead of the catalog concentration', function (): void {
    $user = makePharmacyUser();
    grantPharmacyPermission($user, 'medication.prescribe');
    $patient = makePharmacyPatient();
    ensureIvFluidCatalogItem();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id, [
                'medicationCode' => 'ATC:IVFLUID-NS500',
                'medicationName' => 'Normal Saline 0.9% 500 ml IV infusion',
                'dosageInstruction' => 'Infuse 500 mL IV every 8 hours (q8h).',
                'doseQuantity' => 500,
                'doseUnit' => 'ml',
                'route' => 'intravenous',
                'frequency' => 'q8h',
            ]),
            [
                'safetyAcknowledged' => true,
                'safetyOverrideCode' => 'benefit_outweighs_risk',
                'safetyOverrideReason' => 'Routine IV fluid replacement, no contraindications on file.',
            ],
        ))
        ->assertCreated()
        ->json('data');

    expect($created['doseUnit'])->toBe('ml');
    expect((float) $created['doseQuantity'])->toBe(500.0);
});

it('still rejects a dose unit incompatible with catalog strength for a non-IV-fluid item', function (): void {
    $user = makePharmacyUser();
    grantPharmacyPermission($user, 'medication.prescribe');
    $patient = makePharmacyPatient();
    ensureActiveApprovedMedicineCatalogItem([
        'code' => 'ATC:TESTCONC-01',
        'name' => 'Test Concentration Injection 0.9%',
        'category' => 'antibiotics',
        'unit' => 'ampoule',
        'dosage_form' => 'injection',
        'strength' => '0.9%',
        'route' => 'intramuscular',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id, [
                'medicationCode' => 'ATC:TESTCONC-01',
                'medicationName' => 'Test Concentration Injection 0.9%',
                'doseQuantity' => 500,
                'doseUnit' => 'ml',
            ]),
            ['safetyAcknowledged' => true],
        ))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['doseUnit']);
});

it('rejects a non-volume dose unit for an IV fluid catalog item', function (): void {
    $user = makePharmacyUser();
    grantPharmacyPermission($user, 'medication.prescribe');
    $patient = makePharmacyPatient();
    ensureIvFluidCatalogItem();

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id, [
                'medicationCode' => 'ATC:IVFLUID-NS500',
                'medicationName' => 'Normal Saline 0.9% 500 ml IV infusion',
                'doseQuantity' => 500,
                'doseUnit' => 'mg',
            ]),
            ['safetyAcknowledged' => true],
        ))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['doseUnit']);
});

it('normalizes infusion rate and duration unit synonyms to their canonical tokens', function (): void {
    $user = makePharmacyUser();
    grantPharmacyPermission($user, 'medication.prescribe');
    $patient = makePharmacyPatient();
    ensureIvFluidCatalogItem();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id, [
                'medicationCode' => 'ATC:IVFLUID-NS500',
                'medicationName' => 'Normal Saline 0.9% 500 ml IV infusion',
                'doseQuantity' => 500,
                'doseUnit' => 'ml',
                'infusionRateValue' => 125,
                'infusionRateUnit' => 'ml/hr',
                'infusionDurationValue' => 4,
                'infusionDurationUnit' => 'hrs',
            ]),
            [
                'safetyAcknowledged' => true,
                'safetyOverrideCode' => 'benefit_outweighs_risk',
                'safetyOverrideReason' => 'Routine IV fluid replacement, no contraindications on file.',
            ],
        ))
        ->assertCreated()
        ->json('data');

    expect($created['infusionRateUnit'])->toBe('ml_per_hour');
    expect($created['infusionDurationUnit'])->toBe('hour');
});

it('rejects an unrecognized infusion rate unit', function (): void {
    $user = makePharmacyUser();
    grantPharmacyPermission($user, 'medication.prescribe');
    $patient = makePharmacyPatient();
    ensureIvFluidCatalogItem();

    $this->actingAs($user)
        ->postJson('/api/v1/pharmacy-orders', array_merge(
            pharmacyOrderPayload($patient->id, [
                'medicationCode' => 'ATC:IVFLUID-NS500',
                'medicationName' => 'Normal Saline 0.9% 500 ml IV infusion',
                'doseQuantity' => 500,
                'doseUnit' => 'ml',
                'infusionRateValue' => 30,
                'infusionRateUnit' => 'drops/min',
            ]),
            ['safetyAcknowledged' => true],
        ))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['infusionRateUnit']);
});

/**
 * @return array{0:string,1:string}
 */
function seedPharmacyPlatformScopeFacility(
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
        'facility_type' => 'pharmacy',
        'timezone' => 'Africa/Nairobi',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}
