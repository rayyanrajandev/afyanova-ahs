<?php

use App\Models\User;
use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Modules\Billing\Application\UseCases\CreateBillingInvoiceUseCase;
use App\Jobs\GenerateAuditExportCsvJob;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceAuditLogModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoicePaymentModel;
use App\Modules\Billing\Infrastructure\Models\GLJournalEntryModel;
use App\Modules\Billing\Infrastructure\Models\RevenueRecognitionModel;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\ConsultationMappingModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\AuditExportJobModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeBillingPatient(array $overrides = []): PatientModel
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

function makeBillingAppointment(string $patientId, array $overrides = []): AppointmentModel
{
    return AppointmentModel::query()->create(array_merge([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Billing review',
        'notes' => null,
        'status' => 'completed',
        'status_reason' => null,
    ], $overrides));
}

function makeBillingConsultationTariff(array $overrides = []): BillingServiceCatalogItemModel
{
    return BillingServiceCatalogItemModel::query()->create(array_merge([
        'service_code' => 'CONSULT-OUTPATIENT',
        'service_name' => 'Outpatient Consultation',
        'service_type' => 'consultation',
        'department' => 'Outpatient',
        'unit' => 'visit',
        'base_price' => 35000,
        'currency_code' => 'TZS',
        'tax_rate_percent' => 0,
        'is_taxable' => false,
        'effective_from' => now()->subDay()->toDateTimeString(),
        'effective_to' => null,
        'description' => 'Consultation charge capture tariff',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function makeBillingRadiologyTariff(array $overrides = []): BillingServiceCatalogItemModel
{
    return BillingServiceCatalogItemModel::query()->create(array_merge([
        'service_code' => 'RAD-USA-001',
        'service_name' => 'Abdominal Ultrasound',
        'service_type' => 'radiology',
        'department' => 'Radiology',
        'unit' => 'study',
        'base_price' => 60000,
        'currency_code' => 'TZS',
        'tax_rate_percent' => 0,
        'is_taxable' => false,
        'effective_from' => now()->subDay()->toDateTimeString(),
        'effective_to' => null,
        'description' => 'Radiology charge capture tariff',
        'metadata' => ['modality' => 'ultrasound'],
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function makeBillingBedDayTariff(array $overrides = []): BillingServiceCatalogItemModel
{
    return BillingServiceCatalogItemModel::query()->create(array_merge([
        'service_code' => 'BED-DAY',
        'service_name' => 'Bed Charge',
        'service_type' => 'bed_day',
        'department' => null,
        'unit' => 'day',
        'base_price' => 20000,
        'currency_code' => 'TZS',
        'tax_rate_percent' => 0,
        'is_taxable' => false,
        'effective_from' => now()->subDay()->toDateTimeString(),
        'effective_to' => null,
        'description' => 'Bed-day charge capture tariff',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

/**
 * PricingEngine_Migration_Plan.md Phase 5: bed-day pricing is keyed on the
 * bed's own facility_resources.chargeable_item_id -- the old
 * ward-specific-then-flat string-match cascade is gone. Returns the bed
 * resource so callers can attach it to an admission's bed_resource_id.
 */
function makeBillingBedResourceWithPrice(string $wardName, float $price, string $code = 'BED-DAY', string $name = 'Bed Charge'): FacilityResourceModel
{
    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->fill([
        'catalog_type' => 'bed_day', 'charge_model' => 'flat', 'code' => $code, 'name' => $name, 'status' => 'active',
    ]);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id, 'currency_code' => 'TZS', 'unit_price' => $price, 'status' => 'active',
    ]);

    return FacilityResourceModel::query()->create([
        'resource_type' => 'ward_bed', 'code' => 'BED'.strtoupper(Str::random(6)),
        'name' => $wardName.' Bed', 'ward_name' => $wardName, 'bed_number' => 'A-02',
        'status' => 'active', 'chargeable_item_id' => $chargeableItem->id,
    ]);
}

/**
 * PricingEngine_Migration_Plan.md Phase 5: consultation pricing is keyed
 * on (clinician_tier, department) only, via chargeable_item_id -- the old
 * string-match cascade (specialty-first, then department, then generic)
 * is gone.
 */
function makeBillingConsultationMapping(string $tier, string $department, float $price, string $code = 'CONSULT', string $name = 'Consultation'): ConsultationMappingModel
{
    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->fill([
        'catalog_type' => 'consultation',
        'charge_model' => 'flat',
        'code' => $code,
        'name' => $name,
        'status' => 'active',
    ]);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id,
        'currency_code' => 'TZS',
        'unit_price' => $price,
        'status' => 'active',
    ]);

    return ConsultationMappingModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id,
        'clinician_tier' => $tier,
        'department' => $department,
    ]);
}

function makeBillingStaffProfileForUser(User $user, array $overrides = []): StaffProfileModel
{
    return StaffProfileModel::query()->create(array_merge([
        'user_id' => $user->id,
        'employee_number' => 'EMP'.now()->format('Ymd').strtoupper(Str::random(6)),
        'department' => 'Outpatient',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'LIC'.strtoupper(Str::random(6)),
        'license_type' => 'CO',
        'phone_extension' => null,
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function makeBillingAdmission(string $patientId, array $overrides = []): AdmissionModel
{
    return AdmissionModel::query()->create(array_merge([
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
    ], $overrides));
}

function billingInvoicePayload(string $patientId, array $overrides = []): array
{
    return array_merge([
        'patientId' => $patientId,
        'invoiceDate' => now()->toDateTimeString(),
        'currencyCode' => 'TZS',
        'subtotalAmount' => 100,
        'discountAmount' => 10,
        'taxAmount' => 5,
        'paidAmount' => 20,
        'paymentDueAt' => now()->addDays(7)->toDateTimeString(),
        'notes' => 'Initial invoice',
    ], $overrides);
}

function grantBillingPaymentRoutePermissions(User $user): void
{
    grantBillingInvoiceStatusRoutePermissions($user);
    $user->givePermissionTo('billing.payments.record');
    $user->givePermissionTo('billing.payments.view-history');
}

function grantBillingInvoiceCreatePermission(User $user): void
{
    $user->givePermissionTo('billing.invoices.create');
}

function grantBillingInvoiceReadPermission(User $user): void
{
    $user->givePermissionTo('billing.invoices.read');
}

function grantBillingInvoiceUpdateDraftPermission(User $user): void
{
    $user->givePermissionTo('billing.invoices.update-draft');
}

function makeBillingUser(): User
{
    $user = User::factory()->create();
    grantBillingInvoiceCreatePermission($user);
    grantBillingInvoiceReadPermission($user);

    return $user;
}

function grantBillingInvoiceStatusRoutePermissions(User $user): void
{
    $user->givePermissionTo('billing.invoices.issue');
    $user->givePermissionTo('billing.invoices.cancel');
    $user->givePermissionTo('billing.invoices.void');
}

it('requires authentication for billing invoice creation', function (): void {
    $patient = makeBillingPatient();

    $this->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->assertUnauthorized();
});

it('forbids billing invoice creation without create permission', function (): void {
    $user = User::factory()->create();
    $patient = makeBillingPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->assertForbidden();
});

it('forbids billing invoice list without read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/billing')
        ->assertForbidden();
});

it('forbids billing invoice show without read permission', function (): void {
    $userWithCreate = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($userWithCreate)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->assertCreated()
        ->json('data');

    $userWithoutRead = User::factory()->create();
    grantBillingInvoiceCreatePermission($userWithoutRead);

    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/billing/'.$created['id'])
        ->assertForbidden();
});

it('can create billing invoice for existing patient', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $appointment = makeBillingAppointment($patient->id);
    $admission = makeBillingAdmission($patient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'appointmentId' => $appointment->id,
            'admissionId' => $admission->id,
            'issuedByUserId' => $user->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.appointmentId', $appointment->id)
        ->assertJsonPath('data.admissionId', $admission->id)
        ->assertJsonPath('data.status', 'draft');
});

it('auto-links the single active outpatient appointment when billing draft is created without explicit encounter context', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $appointment = makeBillingAppointment($patient->id, [
        'status' => 'in_consultation',
        'triaged_at' => now()->subMinutes(20)->toDateTimeString(),
        'consultation_started_at' => now()->subMinutes(5)->toDateTimeString(),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->assertCreated()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.appointmentId', $appointment->id)
        ->assertJsonPath('data.admissionId', null)
        ->assertJsonPath('data.status', 'draft');
});

it('defaults billing invoice currency from active country profile when use case is called without currency', function (): void {
    config()->set('country_profiles.active', 'KE');

    $user = User::factory()->create();
    $patient = makeBillingPatient();

    $result = app(CreateBillingInvoiceUseCase::class)->execute([
        'patient_id' => $patient->id,
        'invoice_date' => now()->toDateTimeString(),
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'paid_amount' => 0,
    ], $user->id);

    expect($result['invoice']['currency_code'] ?? null)->toBe('KES')
        ->and($result['draft_reused'] ?? null)->toBeFalse();
});

it('can create billing invoice with line items and returns normalized line item totals', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 80000,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'lineItems' => [
                [
                    'description' => 'Consultation Fee',
                    'quantity' => 1,
                    'unitPrice' => 50000,
                    'serviceCode' => 'CONSULT',
                    'unit' => 'service',
                ],
                [
                    'description' => 'CBC Test',
                    'quantity' => 2,
                    'unitPrice' => 15000,
                    'notes' => 'Urgent processing',
                ],
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.lineItems.0.description', 'Consultation Fee')
        ->assertJsonPath('data.lineItems.0.lineTotal', 50000)
        ->assertJsonPath('data.lineItems.1.description', 'CBC Test')
        ->assertJsonPath('data.lineItems.1.lineTotal', 30000);
});

it('continues the active draft for the same patient billing context instead of creating a second draft', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient([
        'first_name' => 'Shamsa Mohamed',
        'last_name' => 'Farijala',
    ]);
    $consultationSourceId = (string) Str::uuid();
    $radiologySourceId = (string) Str::uuid();

    $firstDraft = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 14500,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
            'lineItems' => [
                [
                    'description' => 'Clinical Officer General OPD Consultation',
                    'quantity' => 1,
                    'unitPrice' => 14500,
                    'serviceCode' => 'CONSULT-CO-GENERAL-OPD',
                    'unit' => 'visit',
                    'sourceWorkflowKind' => 'appointment_consultation',
                    'sourceWorkflowId' => $consultationSourceId,
                ],
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('meta.draftReused', false)
        ->json('data');

    $continuedDraftResponse = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 74500,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
            'lineItems' => [
                [
                    'description' => 'Clinical Officer General OPD Consultation',
                    'quantity' => 1,
                    'unitPrice' => 14500,
                    'serviceCode' => 'CONSULT-CO-GENERAL-OPD',
                    'unit' => 'visit',
                    'sourceWorkflowKind' => 'appointment_consultation',
                    'sourceWorkflowId' => $consultationSourceId,
                ],
                [
                    'description' => 'Abdominal Ultrasound',
                    'quantity' => 1,
                    'unitPrice' => 60000,
                    'serviceCode' => 'RAD-USA-001',
                    'unit' => 'study',
                    'sourceWorkflowKind' => 'radiology_order',
                    'sourceWorkflowId' => $radiologySourceId,
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('meta.draftReused', true)
        ->assertJsonPath('data.id', $firstDraft['id'])
        ->assertJsonPath('data.totalAmount', '74500.00')
        ->assertJsonCount(2, 'data.lineItems');

    $continuedDraft = $continuedDraftResponse->json('data');

    expect(collect($continuedDraft['lineItems'] ?? [])->pluck('serviceCode')->all())
        ->toBe([
            'CONSULT-CO-GENERAL-OPD',
            'RAD-USA-001',
        ]);

    $matchingDraftCount = BillingInvoiceModel::query()
        ->where('patient_id', $patient->id)
        ->where('status', 'draft')
        ->where('currency_code', 'TZS')
        ->whereNull('appointment_id')
        ->whereNull('admission_id')
        ->whereNull('billing_payer_contract_id')
        ->count();

    expect($matchingDraftCount)->toBe(1);
});

it('captures consultation visits as billable invoice source lines', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $clinician = User::factory()->create();
    $appointment = makeBillingAppointment($patient->id, [
        'clinician_user_id' => $clinician->id,
        'consultation_owner_user_id' => $clinician->id,
        'department' => 'Outpatient',
    ]);

    makeBillingStaffProfileForUser($clinician, [
        'job_title' => 'Clinical Officer',
        'license_type' => 'CO',
    ]);

    makeBillingConsultationMapping('CO', 'Outpatient', 35000, 'CONSULT-OUTPATIENT', 'Outpatient Consultation');

    $candidate = $this->actingAs($user)
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&appointmentId='.$appointment->id.'&currencyCode=TZS')
        ->assertOk()
        ->assertJsonPath('meta.pending', 1)
        ->assertJsonPath('meta.priced', 1)
        ->json('data.0');

    expect($candidate['sourceWorkflowKind'])->toBe('appointment_consultation')
        ->and($candidate['sourceWorkflowId'])->toBe($appointment->id)
        ->and($candidate['pricingStatus'])->toBe('priced')
        ->and($candidate['pricingSource'])->toBe('chargeable_item')
        ->and((float) $candidate['unitPrice'])->toBe(35000.0);

    $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'appointmentId' => $appointment->id,
            'subtotalAmount' => 35000,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
            'lineItems' => [
                [
                    'description' => $candidate['serviceName'],
                    'quantity' => 1,
                    'unitPrice' => 35000,
                    'serviceCode' => $candidate['serviceCode'],
                    'unit' => 'visit',
                    'sourceWorkflowKind' => 'appointment_consultation',
                    'sourceWorkflowId' => $appointment->id,
                    'sourceWorkflowLabel' => $appointment->appointment_number,
                    'sourcePerformedAt' => now()->toDateTimeString(),
                ],
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.lineItems.0.sourceWorkflowKind', 'appointment_consultation')
        ->assertJsonPath('data.lineItems.0.sourceWorkflowId', $appointment->id)
        ->assertJsonPath('data.lineItems.0.lineTotal', 35000);
});

it('accepts true false query strings for billing charge capture include invoiced filters', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $appointment = makeBillingAppointment($patient->id);

    makeBillingConsultationTariff();

    $this->actingAs($user)
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&appointmentId='.$appointment->id.'&currencyCode=TZS&includeInvoiced=false')
        ->assertOk()
        ->assertJsonPath('meta.includeInvoiced', false)
        ->assertJsonPath('meta.pending', 1);
});

it('generates one bed-day charge capture candidate per elapsed calendar day of an admission', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $bed = makeBillingBedResourceWithPrice('Ward A', 20000);
    $admission = makeBillingAdmission($patient->id, [
        'bed_resource_id' => $bed->id,
        'admitted_at' => now()->subHours(26)->toDateTimeString(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&admissionId='.$admission->id.'&currencyCode=TZS')
        ->assertOk()
        ->assertJsonPath('meta.pending', 2)
        ->assertJsonPath('meta.priced', 2)
        ->json('data');

    $bedDayCandidates = collect($response)->where('sourceWorkflowKind', 'admission_bed_day')->values();

    expect($bedDayCandidates)->toHaveCount(2)
        ->and($bedDayCandidates->pluck('sourceWorkflowId')->all())->toBe([
            $admission->id.':2',
            $admission->id.':1',
        ])
        ->and($bedDayCandidates->pluck('pricingSource')->unique()->all())->toBe(['chargeable_item'])
        ->and($bedDayCandidates->pluck('unitPrice')->unique()->all())->toBe([20000])
        ->and($bedDayCandidates->pluck('unit')->unique()->all())->toBe(['day']);
});

it('prices bed-day charges via the bed\'s assigned chargeable item, ignoring any legacy tariff', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $bed = makeBillingBedResourceWithPrice('ICU', 120000, 'BED-ICU', 'ICU Bed Charge');
    $admission = makeBillingAdmission($patient->id, [
        'ward' => 'ICU',
        'bed_resource_id' => $bed->id,
        'admitted_at' => now()->subHours(2)->toDateTimeString(),
    ]);

    // Legacy tariffs for the same ward, deliberately present, to prove
    // they're genuinely never consulted anymore.
    makeBillingBedDayTariff();
    makeBillingBedDayTariff([
        'service_code' => 'BED-ICU',
        'service_name' => 'ICU Bed Charge',
        'base_price' => 20000,
    ]);

    $candidate = $this->actingAs($user)
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&admissionId='.$admission->id.'&currencyCode=TZS')
        ->assertOk()
        ->assertJsonPath('meta.pending', 1)
        ->json('data.0');

    expect($candidate['unitPrice'])->toBe(120000)
        ->and($candidate['pricingSource'])->toBe('chargeable_item');
});

it('excludes already-invoiced bed-days while still surfacing newly elapsed ones', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $bed = makeBillingBedResourceWithPrice('Ward A', 20000);
    $admission = makeBillingAdmission($patient->id, [
        'bed_resource_id' => $bed->id,
        'admitted_at' => now()->subHours(26)->toDateTimeString(),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'admissionId' => $admission->id,
            'lineItems' => [
                [
                    'description' => 'Bed Charge',
                    'quantity' => 1,
                    'unitPrice' => 20000,
                    'serviceCode' => 'BED-DAY',
                    'unit' => 'day',
                    'sourceWorkflowKind' => 'admission_bed_day',
                    'sourceWorkflowId' => $admission->id.':1',
                    'sourceWorkflowLabel' => $admission->admission_number,
                    'sourcePerformedAt' => now()->subHours(26)->toDateTimeString(),
                ],
            ],
        ]))
        ->assertCreated();

    $this->actingAs($user)
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&admissionId='.$admission->id.'&currencyCode=TZS')
        ->assertOk()
        ->assertJsonPath('meta.pending', 1)
        ->assertJsonPath('meta.alreadyInvoiced', 1)
        ->assertJsonPath('data.0.sourceWorkflowId', $admission->id.':2');
});

it('prices consultation visits via the clinician tier + department mapping', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $clinician = User::factory()->create();
    $appointment = makeBillingAppointment($patient->id, [
        'clinician_user_id' => $clinician->id,
        'consultation_owner_user_id' => $clinician->id,
        'department' => 'Outpatient',
    ]);

    makeBillingStaffProfileForUser($clinician, [
        'job_title' => 'Clinical Officer',
        'license_type' => 'CO',
    ]);

    // A different tier's mapping for the same department must not apply --
    // proves the lookup is genuinely tier-specific, not just "the only
    // mapping that exists."
    makeBillingConsultationMapping('MD', 'Outpatient', 35000, 'CONSULT-MD-OUTPATIENT', 'Medical Doctor Outpatient Consultation');
    makeBillingConsultationMapping('CO', 'Outpatient', 18000, 'CONSULT-CO-OUTPATIENT', 'Clinical Officer Outpatient Consultation');

    $candidate = $this->actingAs($user)
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&appointmentId='.$appointment->id.'&currencyCode=TZS')
        ->assertOk()
        ->assertJsonPath('meta.pending', 1)
        ->assertJsonPath('meta.priced', 1)
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(18000.0)
        ->and($candidate['pricingStatus'])->toBe('priced')
        ->and($candidate['pricingSource'])->toBe('chargeable_item');
});

/**
 * PricingEngine_Migration_Plan.md Phase 5: the old system could price a
 * specific *specialty* (e.g. Cardiology) differently from a generic
 * specialist, even under the same appointment department. The new
 * ConsultationMappingModel has no specialty dimension -- only
 * (clinician_tier, department) -- so that granularity is gone by design.
 * In practice this means specialty-specific pricing now requires booking
 * the appointment under a specialty-specific department (as this test
 * does), rather than a generic one with specialty resolved from the
 * clinician's profile.
 */
it('prices specialist consultation visits by the appointment department, not the old specialty cascade', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $consultationOwner = User::factory()->create();
    $appointment = makeBillingAppointment($patient->id, [
        'clinician_user_id' => $consultationOwner->id,
        'consultation_owner_user_id' => $consultationOwner->id,
        'department' => 'Cardiology',
    ]);

    makeBillingStaffProfileForUser($consultationOwner, [
        'department' => 'Cardiology',
        'job_title' => 'Consultant Cardiologist',
        'license_type' => 'MD',
    ]);

    // A generic specialist mapping for a different department must not
    // apply -- proves the lookup is genuinely department-specific.
    makeBillingConsultationMapping('SPECIALIST', 'Outpatient', 50000, 'CONSULT-SPECIALIST-OUTPATIENT', 'Specialist Outpatient Consultation');
    makeBillingConsultationMapping('SPECIALIST', 'Cardiology', 65000, 'CONSULT-SPECIALIST-CARDIOLOGY', 'Cardiology Specialist Consultation');

    $candidate = $this->actingAs($user)
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&appointmentId='.$appointment->id.'&currencyCode=TZS')
        ->assertOk()
        ->assertJsonPath('meta.pending', 1)
        ->assertJsonPath('meta.priced', 1)
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(65000.0)
        ->and($candidate['pricingStatus'])->toBe('priced')
        ->and($candidate['pricingSource'])->toBe('chargeable_item');
});

it('prices completed radiology orders via the chargeable item, not the old billing service catalog', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $appointment = makeBillingAppointment($patient->id);

    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'radiology_procedure',
        'code' => 'RAD-USA-001',
        'name' => 'Abdominal Ultrasound',
        'department_id' => null,
        'category' => 'ultrasound',
        'unit' => 'study',
        'description' => 'Abdominal ultrasound test fixture.',
        'metadata' => [
            'billingServiceCode' => 'RAD-USA-001',
            'modality' => 'ultrasound',
            'bodyRegion' => 'abdomen',
            'contrast' => 'none',
            'countryContext' => 'TZ',
        ],
        'status' => 'active',
        'status_reason' => null,
    ]);

    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->id = $catalogItem->id;
    $chargeableItem->fill([
        'catalog_type' => 'radiology_procedure', 'charge_model' => 'flat', 'code' => 'RAD-USA-001', 'name' => 'Abdominal Ultrasound', 'status' => 'active',
    ]);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $catalogItem->id, 'currency_code' => 'TZS', 'unit_price' => 60000, 'status' => 'active',
    ]);

    $order = RadiologyOrderModel::query()->create([
        'order_number' => 'RAD'.now()->format('Ymd').strtoupper(Str::random(6)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => $appointment->id,
        'ordered_by_user_id' => $user->id,
        'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'radiology_procedure_catalog_item_id' => $catalogItem->id,
        'procedure_code' => 'RAD-USA-001',
        'modality' => 'ultrasound',
        'study_description' => 'Abdominal Ultrasound',
        'clinical_indication' => 'Abdominal pain',
        'scheduled_for' => now()->subHour()->toDateTimeString(),
        'report_summary' => 'No acute abnormality.',
        'completed_at' => now()->subMinutes(20)->toDateTimeString(),
        'status' => 'completed',
        'status_reason' => null,
    ]);

    // A legacy tariff for the same code, deliberately present, to prove
    // it's genuinely never consulted anymore.
    makeBillingRadiologyTariff();

    $candidate = $this->actingAs($user)
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&appointmentId='.$appointment->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect($candidate['sourceWorkflowKind'])->toBe('radiology_order')
        ->and($candidate['sourceWorkflowId'])->toBe($order->id)
        ->and($candidate['serviceCode'])->toBe('RAD-USA-001')
        ->and($candidate['pricingStatus'])->toBe('priced')
        ->and($candidate['pricingSource'])->toBe('chargeable_item')
        ->and((float) $candidate['unitPrice'])->toBe(60000.0)
        ->and((float) $candidate['lineTotal'])->toBe(60000.0);
});

it('rejects billing invoice for missing patient', function (): void {
    $user = makeBillingUser();

    $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload((string) Str::uuid()))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['patientId']);
});

it('rejects billing invoice when appointment does not belong to patient', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $otherPatient = makeBillingPatient([
        'phone' => '+255788889999',
        'first_name' => 'Other',
        'last_name' => 'Patient',
    ]);
    $appointment = makeBillingAppointment($otherPatient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'appointmentId' => $appointment->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['appointmentId']);
});

it('rejects billing invoice when admission does not belong to patient', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $otherPatient = makeBillingPatient([
        'phone' => '+255766667777',
        'first_name' => 'Third',
        'last_name' => 'Patient',
    ]);
    $admission = makeBillingAdmission($otherPatient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'admissionId' => $admission->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['admissionId']);
});

it('fetches billing invoice by id', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.id', $created['id']);
});

it('returns 404 for unknown billing invoice id', function (): void {
    $user = makeBillingUser();

    $this->actingAs($user)
        ->getJson('/api/v1/billing/060afc03-2ce9-4b1d-a1c2-326d2722ce25')
        ->assertNotFound();
});

it('updates billing invoice fields', function (): void {
    $user = makeBillingUser();
    grantBillingInvoiceUpdateDraftPermission($user);
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'], [
            'currencyCode' => 'usd',
            'notes' => 'Currency correction and notes update',
        ])
        ->assertOk()
        ->assertJsonPath('data.currencyCode', 'USD')
        ->assertJsonPath('data.notes', 'Currency correction and notes update');
});

it('updates billing invoice line items and returns normalized line totals', function (): void {
    $user = makeBillingUser();
    grantBillingInvoiceUpdateDraftPermission($user);
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 100000,
            'discountAmount' => 0,
            'taxAmount' => 0,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'], [
            'lineItems' => [
                [
                    'description' => 'Consultation',
                    'quantity' => 1,
                    'unitPrice' => 60000,
                ],
                [
                    'description' => 'Medication Pack',
                    'quantity' => 2,
                    'unitPrice' => 12500,
                    'serviceCode' => 'MED-PACK',
                ],
            ],
            'subtotalAmount' => 85000,
        ])
        ->assertOk()
        ->assertJsonPath('data.lineItems.0.description', 'Consultation')
        ->assertJsonPath('data.lineItems.0.lineTotal', 60000)
        ->assertJsonPath('data.lineItems.1.serviceCode', 'MED-PACK')
        ->assertJsonPath('data.lineItems.1.lineTotal', 25000);
});

it('rejects billing invoice line item updates after invoice is issued', function (): void {
    $user = makeBillingUser();
    grantBillingInvoiceUpdateDraftPermission($user);
    grantBillingInvoiceStatusRoutePermissions($user);
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'issued',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'], [
            'lineItems' => [
                [
                    'description' => 'Late change after issue',
                    'quantity' => 1,
                    'unitPrice' => 10000,
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lineItems']);
});

it('rejects structural billing invoice updates after invoice is issued', function (): void {
    $user = makeBillingUser();
    grantBillingInvoiceUpdateDraftPermission($user);
    grantBillingInvoiceStatusRoutePermissions($user);
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 100000,
            'discountAmount' => 0,
            'taxAmount' => 0,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'issued',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'], [
            'subtotalAmount' => 120000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['subtotalAmount']);
});

it('rejects empty billing invoice patch payload', function (): void {
    $user = makeBillingUser();
    grantBillingInvoiceUpdateDraftPermission($user);
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'], [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payload']);
});

it('forbids billing invoice update without update-draft permission', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'], [
            'notes' => 'Unauthorized update attempt',
        ])
        ->assertForbidden();
});

it('rejects billing invoice detail update when status fields are provided', function (): void {
    $user = makeBillingUser();
    grantBillingInvoiceUpdateDraftPermission($user);
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'], [
            'notes' => 'Detail update',
            'status' => 'cancelled',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('updates billing invoice status and paid amount', function (): void {
    $user = makeBillingUser();
    $user->givePermissionTo('billing.payments.record');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 150,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'partially_paid',
            'paidAmount' => 50,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'partially_paid');

    $record = BillingInvoiceModel::query()->find($created['id']);
    expect((float) $record?->paid_amount)->toBe(50.0);
    expect((float) $record?->balance_amount)->toBe(100.0);
    expect($record?->last_payment_at)->not->toBeNull();
});

it('writes billing invoice status transition parity metadata in audit logs', function (): void {
    $user = makeBillingUser();
    grantBillingInvoiceStatusRoutePermissions($user);
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 150,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'voided',
            'reason' => 'Duplicate invoice reference',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided');

    $statusAudit = BillingInvoiceAuditLogModel::query()
        ->where('billing_invoice_id', $created['id'])
        ->where('action', 'billing-invoice.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();
    expect($statusAudit?->metadata ?? [])->toMatchArray([
        'transition' => [
            'from' => 'draft',
            'to' => 'voided',
        ],
        'reason_required' => true,
        'reason_provided' => true,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'billing-invoice.status.updated');
});

it('forbids issuing billing invoice status without issue permission', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'issued',
        ])
        ->assertForbidden();
});

it('forbids voiding billing invoice status without void permission', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'voided',
            'reason' => 'duplicate invoice',
        ])
        ->assertForbidden();
});

it('forbids marking billing invoice paid without payment record permission', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'paid',
        ])
        ->assertForbidden();
});

it('forbids marking billing invoice partially paid without payment record permission', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'partially_paid',
            'paidAmount' => 10,
        ])
        ->assertForbidden();
});

it('forbids reverting billing invoice to draft without update-draft permission', function (): void {
    $user = makeBillingUser();
    $user->givePermissionTo('billing.payments.record');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'draft',
        ])
        ->assertForbidden();
});

it('allows reverting billing invoice to draft with update-draft permission', function (): void {
    $user = makeBillingUser();
    $user->givePermissionTo('billing.invoices.update-draft');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'draft',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');
});

it('stores last payment metadata when billing invoice payment increases', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 200,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'partially_paid',
            'paidAmount' => 125,
            'paymentPayerType' => 'self_pay',
            'paymentMethod' => 'mobile_money',
            'paymentReference' => 'M-PESA-TXN-001',
        ])
        ->assertOk()
        ->assertJsonPath('data.lastPaymentPayerType', 'self_pay')
        ->assertJsonPath('data.lastPaymentMethod', 'mobile_money')
        ->assertJsonPath('data.lastPaymentReference', 'M-PESA-TXN-001');

    $record = BillingInvoiceModel::query()->findOrFail($created['id']);
    expect($record->last_payment_payer_type)->toBe('self_pay');
    expect($record->last_payment_method)->toBe('mobile_money');
    expect($record->last_payment_reference)->toBe('M-PESA-TXN-001');

    $payments = BillingInvoicePaymentModel::query()
        ->where('billing_invoice_id', $created['id'])
        ->orderBy('payment_at')
        ->get();

    expect($payments)->toHaveCount(1);
    expect((float) $payments[0]->amount)->toBe(125.0);
    expect((float) $payments[0]->cumulative_paid_amount)->toBe(125.0);
    expect($payments[0]->payer_type)->toBe('self_pay');
    expect($payments[0]->payment_method)->toBe('mobile_money');
    expect($payments[0]->payment_reference)->toBe('M-PESA-TXN-001');
});

it('lists billing invoice payment ledger entries', function (): void {
    $user = makeBillingUser();
    grantBillingPaymentRoutePermissions($user);
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 300,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'partially_paid',
            'paidAmount' => 100,
            'paymentPayerType' => 'insurance',
            'paymentMethod' => 'bank_transfer',
            'paymentReference' => 'BANK-REF-100',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'paid',
            'paidAmount' => 300,
            'paymentPayerType' => 'insurance',
            'paymentMethod' => 'bank_transfer',
            'paymentReference' => 'BANK-REF-200',
        ])
        ->assertOk();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'].'/payments')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(2);
    expect($response->json('data.0.billingInvoiceId'))->toBe($created['id']);
    expect((float) $response->json('data.0.amount'))->toBe(200.0);
    expect((float) $response->json('data.0.cumulativePaidAmount'))->toBe(300.0);
    expect($response->json('data.0.paymentReference'))->toBe('BANK-REF-200');
    expect((float) $response->json('data.1.amount'))->toBe(100.0);
    expect((float) $response->json('data.1.cumulativePaidAmount'))->toBe(100.0);
});

it('forbids billing invoice payment ledger history without view-history permission', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 300,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'].'/payments')
        ->assertForbidden();
});

it('records billing invoice payment through dedicated payment endpoint', function (): void {
    $user = makeBillingUser();
    grantBillingPaymentRoutePermissions($user);
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 200,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'issued',
        ])
        ->assertOk();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments', [
            'amount' => 80,
            'payerType' => 'self_pay',
            'paymentMethod' => 'cash',
            'paymentReference' => 'RCPT-80',
            'note' => 'Front desk cash payment',
        ])
        ->assertCreated();

    expect($response->json('data.invoice.id'))->toBe($created['id']);
    expect($response->json('data.invoice.status'))->toBe('partially_paid');
    expect((float) $response->json('data.invoice.paidAmount'))->toBe(80.0);
    expect((float) $response->json('data.invoice.balanceAmount'))->toBe(120.0);
    expect((float) $response->json('data.payment.amount'))->toBe(80.0);
    expect((float) $response->json('data.payment.cumulativePaidAmount'))->toBe(80.0);
    expect($response->json('data.payment.paymentMethod'))->toBe('cash');
    expect($response->json('data.payment.sourceAction'))->toBe('billing-invoice.payment.recorded');

    $recognition = RevenueRecognitionModel::query()
        ->where('billing_invoice_id', $created['id'])
        ->first();

    expect($recognition)->not->toBeNull();
    expect((float) $recognition->amount_recognized)->toBe(200.0);
    expect((float) $recognition->net_revenue)->toBe(200.0);

    expect(
        GLJournalEntryModel::query()
            ->where('reference_type', 'revenue_recognition')
            ->where('reference_id', $created['id'])
            ->count()
    )->toBe(2);
    expect(
        GLJournalEntryModel::query()
            ->where('reference_type', 'payment')
            ->where('reference_id', $response->json('data.payment.id'))
            ->where('status', 'posted')
            ->count()
    )->toBe(2);
});

it('returns invoice finance posting summary for details workflow', function (): void {
    $user = makeBillingUser();
    grantBillingPaymentRoutePermissions($user);
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 240,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'issued',
        ])
        ->assertOk();

    $payment = $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments', [
            'amount' => 120,
            'payerType' => 'self_pay',
            'paymentMethod' => 'cash',
            'paymentReference' => 'RCPT-120',
        ])
        ->assertCreated()
        ->json('data.payment');

    $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'].'/finance-posting')
        ->assertOk()
        ->assertJsonPath('data.infrastructure.revenueRecognitionReady', true)
        ->assertJsonPath('data.infrastructure.glPostingReady', true)
        ->assertJsonPath('data.recognition.status', 'recognized')
        ->assertJsonPath('data.recognition.netRevenue', 240)
        ->assertJsonPath('data.revenuePosting.postedCount', 2)
        ->assertJsonPath('data.paymentPosting.postedCount', 2)
        ->assertJsonPath('data.refundPosting.entryCount', 0);
});

it('forbids dedicated payment endpoint without billing payment record permission', function (): void {
    $user = makeBillingUser();
    $user->givePermissionTo('billing.invoices.issue');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 200,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'issued',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments', [
            'amount' => 80,
            'payerType' => 'self_pay',
            'paymentMethod' => 'cash',
        ])
        ->assertForbidden();
});

it('rejects dedicated payment endpoint for draft billing invoices', function (): void {
    $user = makeBillingUser();
    grantBillingPaymentRoutePermissions($user);
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 200,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments', [
            'amount' => 50,
            'payerType' => 'self_pay',
            'paymentMethod' => 'cash',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

it('reverses billing invoice payment through dedicated reversal endpoint using compensating ledger entry', function (): void {
    $user = makeBillingUser();
    grantBillingPaymentRoutePermissions($user);
    $user->givePermissionTo('billing.payments.reverse');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 200,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'issued',
        ])
        ->assertOk();

    $payment = $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments', [
            'amount' => 120,
            'payerType' => 'self_pay',
            'paymentMethod' => 'cash',
            'paymentReference' => 'RCPT-120',
        ])
        ->assertCreated()
        ->json('data.payment');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments/'.$payment['id'].'/reversals', [
            'amount' => 20,
            'reason' => 'Cashier posted wrong amount',
            'approvalCaseReference' => 'BILL-REV-0001',
            'note' => 'Corrected before shift close',
        ])
        ->assertCreated();

    expect($response->json('data.invoice.status'))->toBe('partially_paid');
    expect((float) $response->json('data.invoice.paidAmount'))->toBe(100.0);
    expect((float) $response->json('data.invoice.balanceAmount'))->toBe(100.0);

    expect($response->json('data.reversal.entryType'))->toBe('reversal');
    expect($response->json('data.reversal.reversalOfPaymentId'))->toBe($payment['id']);
    expect($response->json('data.reversal.reversalReason'))->toBe('Cashier posted wrong amount');
    expect($response->json('data.reversal.approvalCaseReference'))->toBe('BILL-REV-0001');
    expect((float) $response->json('data.reversal.amount'))->toBe(-20.0);
    expect((float) $response->json('data.reversal.cumulativePaidAmount'))->toBe(100.0);
    expect($response->json('data.reversal.sourceAction'))->toBe('billing-invoice.payment.reversed');

    $payments = BillingInvoicePaymentModel::query()
        ->where('billing_invoice_id', $created['id'])
        ->orderBy('created_at')
        ->get();

    expect($payments)->toHaveCount(2);
    expect($payments[0]->entry_type)->toBe('payment');
    expect($payments[1]->entry_type)->toBe('reversal');
    expect($payments[1]->reversal_of_payment_id)->toBe($payment['id']);
    expect((float) $payments[1]->amount)->toBe(-20.0);

    expect(
        GLJournalEntryModel::query()
            ->where('reference_type', 'payment')
            ->where('reference_id', $response->json('data.reversal.id'))
            ->where('status', 'reversed')
            ->count()
    )->toBe(2);
});

it('rejects billing payment reversal when amount exceeds remaining reversible amount', function (): void {
    $user = makeBillingUser();
    grantBillingPaymentRoutePermissions($user);
    $user->givePermissionTo('billing.payments.reverse');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 300,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', ['status' => 'issued'])
        ->assertOk();

    $payment = $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments', [
            'amount' => 100,
            'payerType' => 'self_pay',
            'paymentMethod' => 'cash',
        ])
        ->assertCreated()
        ->json('data.payment');

    $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments/'.$payment['id'].'/reversals', [
            'amount' => 60,
            'reason' => 'First correction',
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments/'.$payment['id'].'/reversals', [
            'amount' => 50,
            'reason' => 'Over reversal attempt',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

it('forbids billing payment reversal without permission', function (): void {
    $user = makeBillingUser();
    $user->givePermissionTo('billing.invoices.issue');
    $user->givePermissionTo('billing.payments.record');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 200,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', ['status' => 'issued'])
        ->assertOk();

    $payment = $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments', [
            'amount' => 50,
            'payerType' => 'self_pay',
            'paymentMethod' => 'cash',
        ])
        ->assertCreated()
        ->json('data.payment');

    $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments/'.$payment['id'].'/reversals', [
            'amount' => 10,
            'reason' => 'Unauthorized reversal attempt',
        ])
        ->assertForbidden();
});

it('requires approval case reference for reversal at or above configured threshold', function (): void {
    config()->set('billing.payments.reversal.approval_case_reference_required_at_or_above_amount', 25.0);

    $user = makeBillingUser();
    grantBillingPaymentRoutePermissions($user);
    $user->givePermissionTo('billing.payments.reverse');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 200,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', ['status' => 'issued'])
        ->assertOk();

    $payment = $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments', [
            'amount' => 100,
            'payerType' => 'self_pay',
            'paymentMethod' => 'cash',
        ])
        ->assertCreated()
        ->json('data.payment');

    $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments/'.$payment['id'].'/reversals', [
            'amount' => 30,
            'reason' => 'Supervisor correction needed',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['approvalCaseReference']);
});

it('requires approval case reference for reversals on paid invoices when policy is enabled', function (): void {
    config()->set('billing.payments.reversal.approval_case_reference_required_for_paid_invoice_reversals', true);

    $user = makeBillingUser();
    grantBillingPaymentRoutePermissions($user);
    $user->givePermissionTo('billing.payments.reverse');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 200,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', ['status' => 'issued'])
        ->assertOk();

    $payment = $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments', [
            'amount' => 200,
            'payerType' => 'self_pay',
            'paymentMethod' => 'cash',
        ])
        ->assertCreated()
        ->json('data.payment');

    expect($payment)->not->toBeNull();

    $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments/'.$payment['id'].'/reversals', [
            'amount' => 10,
            'reason' => 'Correction on paid invoice',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['approvalCaseReference']);
});

it('filters billing invoice payment ledger entries by method and payment date', function (): void {
    $user = makeBillingUser();
    $user->givePermissionTo('billing.payments.view-history');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 500,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'partially_paid',
            'paidAmount' => 150,
            'paymentPayerType' => 'self_pay',
            'paymentMethod' => 'cash',
            'paymentReference' => 'CASH-001',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'paid',
            'paidAmount' => 500,
            'paymentPayerType' => 'insurance',
            'paymentMethod' => 'bank_transfer',
            'paymentReference' => 'BANK-500',
        ])
        ->assertOk();

    $payments = BillingInvoicePaymentModel::query()
        ->where('billing_invoice_id', $created['id'])
        ->orderBy('payment_at')
        ->get();

    expect($payments)->toHaveCount(2);

    $payments[0]->forceFill(['payment_at' => '2026-02-20 10:00:00'])->save();
    $payments[1]->forceFill(['payment_at' => '2026-02-26 14:30:00'])->save();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'].'/payments?paymentMethod=bank_transfer&from=2026-02-26&to=2026-02-26')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.paymentMethod'))->toBe('bank_transfer');
    expect($response->json('data.0.paymentReference'))->toBe('BANK-500');
});

it('sets full paid amount when status becomes paid without paid amount input', function (): void {
    $user = makeBillingUser();
    $user->givePermissionTo('billing.payments.record');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 100,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'paidAmount' => 0,
        ]))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'paid',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    $record = BillingInvoiceModel::query()->find($created['id']);
    expect((float) $record?->paid_amount)->toBe(100.0);
    expect((float) $record?->balance_amount)->toBe(0.0);
});

it('enforces reason on cancelled billing invoice status', function (): void {
    $user = makeBillingUser();
    $user->givePermissionTo('billing.invoices.cancel');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'cancelled',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('forbids cancelling billing invoice status without cancel permission', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', [
            'status' => 'cancelled',
            'reason' => 'test cancel authorization',
        ])
        ->assertForbidden();
});

it('writes billing invoice audit logs for create update and status change', function (): void {
    $user = makeBillingUser();
    grantBillingInvoiceUpdateDraftPermission($user);
    $user->givePermissionTo('billing.invoices.issue');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/billing/'.$created['id'], [
        'notes' => 'audit update check',
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/billing/'.$created['id'].'/status', [
        'status' => 'issued',
    ])->assertOk();

    $logs = BillingInvoiceAuditLogModel::query()
        ->where('billing_invoice_id', $created['id'])
        ->orderBy('created_at')
        ->get();

    expect($logs)->toHaveCount(3);
    expect($logs->pluck('action')->all())->toContain(
        'billing-invoice.created',
        'billing-invoice.updated',
        'billing-invoice.status.updated',
    );
    expect($logs->first()->actor_id)->toBe($user->id);
});

it('lists billing invoice audit logs when authorized', function (): void {
    $user = makeBillingUser();
    grantBillingInvoiceUpdateDraftPermission($user);
    $user->givePermissionTo('billing.invoices.void');
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/billing/'.$created['id'], [
        'notes' => 'audit pagination check',
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/billing/'.$created['id'].'/status', [
        'status' => 'voided',
        'reason' => 'duplicate invoice',
    ])->assertOk();

    BillingInvoiceAuditLogModel::query()->create([
        'billing_invoice_id' => $created['id'],
        'action' => 'billing-invoice.document.pdf.downloaded',
        'actor_id' => $user->id,
        'changes' => [],
        'metadata' => [
            'document_filename' => 'afyanova_invoice.pdf',
            'request_ip' => '203.0.113.10',
        ],
        'created_at' => now()->addSecond(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'].'/audit-logs?perPage=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('data.0.action', 'billing-invoice.document.pdf.downloaded')
        ->assertJsonPath('data.0.actionLabel', 'PDF Downloaded')
        ->assertJsonPath('data.0.actor.id', $user->id)
        ->assertJsonPath('data.1.action', 'billing-invoice.status.updated');
});

it('filters billing invoice audit logs by action text actor type and actor id', function (): void {
    $user = makeBillingUser();
    grantBillingInvoiceUpdateDraftPermission($user);
    $user->givePermissionTo('billing.invoices.void');
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/billing/'.$created['id'], [
        'notes' => 'audit filter update',
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/billing/'.$created['id'].'/status', [
        'status' => 'voided',
        'reason' => 'audit filter check',
    ])->assertOk();

    BillingInvoiceAuditLogModel::query()->create([
        'billing_invoice_id' => $created['id'],
        'action' => 'billing-invoice.system.reconciled',
        'actor_id' => null,
        'changes' => [],
        'metadata' => ['source' => 'system-job'],
        'created_at' => now()->addSecond(),
    ]);

    $this->actingAs($user)
        ->getJson(
            '/api/v1/billing/'.$created['id']
                .'/audit-logs?actorType=user&actorId='.$user->id
                .'&action=billing-invoice.status.updated&q=STATUS',
        )
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'billing-invoice.status.updated')
        ->assertJsonPath('data.0.actorId', $user->id);

    $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'].'/audit-logs?actorType=system&q=RECONCILED')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'billing-invoice.system.reconciled')
        ->assertJsonPath('data.0.actorId', null);
});

it('exports billing invoice audit logs as csv when authorized and applies filters', function (): void {
    $user = makeBillingUser();
    grantBillingInvoiceUpdateDraftPermission($user);
    $user->givePermissionTo('billing.invoices.void');
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)->patchJson('/api/v1/billing/'.$created['id'], [
        'notes' => 'audit export update',
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/billing/'.$created['id'].'/status', [
        'status' => 'voided',
        'reason' => 'audit export check',
    ])->assertOk();

    BillingInvoiceAuditLogModel::query()->create([
        'billing_invoice_id' => $created['id'],
        'action' => 'billing-invoice.system.reconciled',
        'actor_id' => null,
        'changes' => [],
        'metadata' => ['source' => 'system-job'],
        'created_at' => now()->addSecond(),
    ]);

    $response = $this->actingAs($user)
        ->get('/api/v1/billing/'.$created['id'].'/audit-logs/export?actorType=system&q=RECONCILED');

    $response->assertOk();
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    $response->assertHeader('X-Export-System-Name', 'Afyanova AHS');
    $response->assertHeader('X-Export-System-Slug', 'afyanova_ahs');
    expect((string) $response->headers->get('content-type'))->toContain('text/csv');
    expect((string) $response->headers->get('content-disposition'))->toContain('afyanova_ahs_billing_audit_');
    expect($response->streamedContent())->toContain('createdAt,action,actorType,actorId,changes,metadata');
    expect($response->streamedContent())->toContain('billing-invoice.system.reconciled');
});

it('creates billing invoice audit log csv export job when authorized', function (): void {
    Queue::fake();

    $user = makeBillingUser();
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/audit-logs/export-jobs', [
            'actorType' => 'system',
            'q' => 'reconciled',
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.schemaVersion', 'audit-log-csv.v1')
        ->assertJsonPath('data.downloadUrl', null);

    $jobId = (string) $response->json('data.id');
    $job = AuditExportJobModel::query()->findOrFail($jobId);

    expect($job->module)->toBe(GenerateAuditExportCsvJob::MODULE_BILLING);
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

it('shows billing invoice audit log csv export job status for creator', function (): void {
    $user = makeBillingUser();
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $job = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_BILLING,
        'target_resource_id' => $created['id'],
        'status' => 'completed',
        'row_count' => 4,
        'file_path' => 'audit-exports/test-billing-status.csv',
        'file_name' => 'billing_status.csv',
        'created_by_user_id' => $user->id,
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'].'/audit-logs/export-jobs/'.$job->id)
        ->assertOk()
        ->assertJsonPath('data.id', (string) $job->id)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.rowCount', 4)
        ->assertJsonPath('data.schemaVersion', 'audit-log-csv.v1')
        ->assertJsonPath(
            'data.downloadUrl',
            '/api/v1/billing/'.$created['id'].'/audit-logs/export-jobs/'.$job->id.'/download',
        );
});

it('downloads completed billing invoice audit log csv export job', function (): void {
    Storage::fake('local');

    $user = makeBillingUser();
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $filePath = 'audit-exports/test-billing-download.csv';
    Storage::disk('local')->put($filePath, "createdAt,action,actorType,actorId,changes,metadata\n");

    $job = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_BILLING,
        'target_resource_id' => $created['id'],
        'status' => 'completed',
        'row_count' => 1,
        'file_path' => $filePath,
        'file_name' => 'billing_download.csv',
        'created_by_user_id' => $user->id,
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get('/api/v1/billing/'.$created['id'].'/audit-logs/export-jobs/'.$job->id.'/download');

    $response->assertOk();
    $response->assertDownload('billing_download.csv');
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    $response->assertHeader('X-Export-System-Name', 'Afyanova AHS');
    $response->assertHeader('X-Export-System-Slug', 'afyanova_ahs');
    expect((string) $response->headers->get('content-type'))->toContain('text/csv');
});

it('returns 409 when billing invoice audit log csv export job is not ready', function (): void {
    $user = makeBillingUser();
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $job = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_BILLING,
        'target_resource_id' => $created['id'],
        'status' => 'queued',
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'].'/audit-logs/export-jobs/'.$job->id.'/download')
        ->assertStatus(409)
        ->assertJsonPath('code', 'EXPORT_JOB_NOT_READY');
});

it('lists billing invoice audit log csv export jobs for creator only', function (): void {
    $user = makeBillingUser();
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $otherUser = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $jobOne = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_BILLING,
        'target_resource_id' => $created['id'],
        'status' => 'failed',
        'filters' => ['actorType' => 'system'],
        'created_by_user_id' => $user->id,
        'error_message' => 'test failure',
    ]);
    $jobTwo = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_BILLING,
        'target_resource_id' => $created['id'],
        'status' => 'completed',
        'row_count' => 2,
        'file_path' => 'audit-exports/test-billing-history.csv',
        'file_name' => 'billing_history.csv',
        'created_by_user_id' => $user->id,
        'completed_at' => now(),
    ]);
    AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_BILLING,
        'target_resource_id' => $created['id'],
        'status' => 'queued',
        'created_by_user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'].'/audit-logs/export-jobs?perPage=10');

    $response->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.perPage', 10);

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain((string) $jobOne->id, (string) $jobTwo->id);
});

it('retries billing invoice audit log csv export job when authorized', function (): void {
    Queue::fake();

    $user = makeBillingUser();
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $sourceJob = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_BILLING,
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
        ->postJson('/api/v1/billing/'.$created['id'].'/audit-logs/export-jobs/'.$sourceJob->id.'/retry');

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.downloadUrl', null);

    $retryJobId = (string) $response->json('data.id');
    expect($retryJobId)->not()->toBe((string) $sourceJob->id);

    $retryJob = AuditExportJobModel::query()->findOrFail($retryJobId);
    expect($retryJob->module)->toBe(GenerateAuditExportCsvJob::MODULE_BILLING);
    expect($retryJob->target_resource_id)->toBe($created['id']);
    expect($retryJob->created_by_user_id)->toBe($user->id);
    expect($retryJob->filters)->toMatchArray($sourceJob->filters ?? []);

    Queue::assertPushed(GenerateAuditExportCsvJob::class, 1);
});

it('forbids billing invoice audit log access without permission', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('forbids billing invoice audit log csv export job retry without permission', function (): void {
    Queue::fake();

    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $sourceJob = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_BILLING,
        'target_resource_id' => $created['id'],
        'status' => 'failed',
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/audit-logs/export-jobs/'.$sourceJob->id.'/retry')
        ->assertForbidden();
});

it('forbids billing invoice audit log csv export job create without permission', function (): void {
    Queue::fake();

    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/audit-logs/export-jobs')
        ->assertForbidden();
});

it('forbids billing invoice audit log csv export access without permission', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->get('/api/v1/billing/'.$created['id'].'/audit-logs/export')
        ->assertForbidden();
});

it('forbids billing invoice audit logs when gate override denies', function (): void {
    Gate::define('billing-invoices.view-audit-logs', static fn (): bool => false);

    $user = makeBillingUser();
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $patient = makeBillingPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->json('data');

    $this->actingAs($user)
        ->getJson('/api/v1/billing/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('returns 404 for billing invoice audit logs of unknown id', function (): void {
    $user = makeBillingUser();
    $user->givePermissionTo('billing-invoices.view-audit-logs');

    $this->actingAs($user)
        ->getJson('/api/v1/billing/060afc03-2ce9-4b1d-a1c2-326d2722ce25/audit-logs')
        ->assertNotFound();
});

it('lists and filters billing invoices', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225AAAAAA',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->subDays(1)->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'tax_amount' => 10,
        'total_amount' => 110,
        'paid_amount' => 0,
        'balance_amount' => 110,
        'payment_due_at' => now()->addDays(7)->toDateTimeString(),
        'notes' => 'Consultation',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225BBBBBB',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->subDays(2)->toDateTimeString(),
        'currency_code' => 'USD',
        'subtotal_amount' => 300,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 300,
        'paid_amount' => 300,
        'balance_amount' => 0,
        'payment_due_at' => now()->addDays(3)->toDateTimeString(),
        'notes' => 'Procedure',
        'status' => 'paid',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/billing?q=Consultation&status=draft&currencyCode=TZS')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.invoiceNumber', 'INV20260225AAAAAA')
        ->assertJsonPath('data.0.status', 'draft');
});

it('lists billing invoices with multi-status filter using statusIn', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225MULTI1',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->subDay()->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 100,
        'paid_amount' => 0,
        'balance_amount' => 100,
        'payment_due_at' => now()->addDays(7)->toDateTimeString(),
        'notes' => 'Draft item',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225MULTI2',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->subDay()->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 200,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 200,
        'paid_amount' => 0,
        'balance_amount' => 200,
        'payment_due_at' => now()->addDays(7)->toDateTimeString(),
        'notes' => 'Issued item',
        'status' => 'issued',
        'status_reason' => null,
    ]);

    BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225MULTI3',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->subDay()->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 300,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 300,
        'paid_amount' => 150,
        'balance_amount' => 150,
        'payment_due_at' => now()->addDays(7)->toDateTimeString(),
        'notes' => 'Partial item',
        'status' => 'partially_paid',
        'status_reason' => null,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/billing?statusIn[]=issued&statusIn[]=partially_paid')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(2);
    expect(collect($response->json('data'))->pluck('status')->sort()->values()->all())
        ->toBe(['issued', 'partially_paid']);
});

it('lists billing invoices by payment activity date range', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $todayInvoice = BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225PAYTODAY',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->subDays(3)->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 300,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 300,
        'paid_amount' => 100,
        'last_payment_at' => now()->startOfDay()->addHours(9)->toDateTimeString(),
        'balance_amount' => 200,
        'payment_due_at' => now()->addDays(7)->toDateTimeString(),
        'notes' => 'Payment activity today',
        'status' => 'partially_paid',
        'status_reason' => null,
    ]);

    BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225PAYOLD',
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->subDays(5)->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 400,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 400,
        'paid_amount' => 400,
        'last_payment_at' => now()->subDays(2)->startOfDay()->addHours(10)->toDateTimeString(),
        'balance_amount' => 0,
        'payment_due_at' => now()->addDays(7)->toDateTimeString(),
        'notes' => 'Payment activity old',
        'status' => 'paid',
        'status_reason' => null,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/billing?paymentActivityFrom='.now()->startOfDay()->toDateTimeString().'&paymentActivityTo='.now()->endOfDay()->toDateTimeString())
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($todayInvoice->id);
});

it('stamps billing invoice tenant and facility scope when created under resolved platform scope', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();

    [$tenantId, $facilityId] = seedBillingPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'TZB',
        tenantName: 'Tanzania Billing Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-BIL',
        facilityName: 'Dar Billing Center',
    );

    $created = $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'TZB',
            'X-Facility-Code' => 'DAR-BIL',
        ])
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->assertCreated()
        ->json('data');

    $row = BillingInvoiceModel::query()->findOrFail($created['id']);

    expect($row->tenant_id)->toBe($tenantId);
    expect($row->facility_id)->toBe($facilityId);
});

it('filters billing invoice reads by facility scope when platform multi facility scoping is enabled', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', true);

    $user = makeBillingUser();
    grantBillingInvoiceUpdateDraftPermission($user);
    $patient = makeBillingPatient();

    [$tenantId, $facilityId] = seedBillingPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-BIL',
        facilityName: 'Nairobi Billing Center',
    );

    [, $otherFacilityId] = seedBillingPlatformScopeFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-BIL',
        facilityName: 'Mombasa Billing Center',
    );

    $visible = BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225SCOPB1',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->subHour()->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'tax_amount' => 10,
        'total_amount' => 110,
        'paid_amount' => 0,
        'balance_amount' => 110,
        'payment_due_at' => now()->addDays(7)->toDateTimeString(),
        'notes' => 'Scoped visible invoice',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    $hidden = BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225SCOPB2',
        'tenant_id' => $tenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->subHours(2)->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 200,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 200,
        'paid_amount' => 0,
        'balance_amount' => 200,
        'payment_due_at' => now()->addDays(3)->toDateTimeString(),
        'notes' => 'Scoped hidden invoice',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-BIL',
        ])
        ->getJson('/api/v1/billing')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.notes', 'Scoped visible invoice');

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-BIL',
        ])
        ->getJson('/api/v1/billing/'.$hidden->id)
        ->assertNotFound();

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-BIL',
        ])
        ->patchJson('/api/v1/billing/'.$hidden->id, [
            'notes' => 'Attempted cross-facility update',
        ])
        ->assertNotFound();
});

it('filters billing invoice reads by facility scope when enabled via feature flag override', function (): void {
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', false);

    $user = makeBillingUser();
    $patient = makeBillingPatient();

    [$tenantId, $facilityId] = seedBillingPlatformScopeAssignment(
        userId: $user->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-BIL',
        facilityName: 'Nairobi Billing Center',
    );

    [, $otherFacilityId] = seedBillingPlatformScopeFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'MSA-BIL',
        facilityName: 'Mombasa Billing Center',
    );

    DB::table('feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_name' => 'platform.multi_facility_scoping',
        'scope_type' => 'country',
        'scope_key' => 'KE',
        'enabled' => true,
        'reason' => 'enable scoping for Kenya billing rollout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $visible = BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225SCOPB3',
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->subHour()->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'tax_amount' => 10,
        'total_amount' => 110,
        'paid_amount' => 0,
        'balance_amount' => 110,
        'payment_due_at' => now()->addDays(7)->toDateTimeString(),
        'notes' => 'Override visible invoice',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225SCOPB4',
        'tenant_id' => $tenantId,
        'facility_id' => $otherFacilityId,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->subHours(2)->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 200,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 200,
        'paid_amount' => 0,
        'balance_amount' => 200,
        'payment_due_at' => now()->addDays(3)->toDateTimeString(),
        'notes' => 'Override hidden invoice',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-BIL',
        ])
        ->getJson('/api/v1/billing')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.notes', 'Override visible invoice');
});

it('includes finance posting summary in the billing invoice queue list', function (): void {
    $user = makeBillingUser();
    grantBillingPaymentRoutePermissions($user);
    $patient = makeBillingPatient();

    $invoice = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$invoice['id'].'/status', [
            'status' => 'issued',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$invoice['id'].'/payments', [
            'amount' => 30,
            'payerType' => 'self_pay',
            'paymentMethod' => 'cash',
            'paymentReference' => 'CASH-LIST-001',
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->getJson('/api/v1/billing')
        ->assertOk()
        ->assertJsonPath('data.0.id', $invoice['id'])
        ->assertJsonPath('data.0.financePosting.recognition.status', 'recognized')
        ->assertJsonPath('data.0.financePosting.paymentPosting.postedCount', 2)
        ->assertJsonPath('data.0.financePosting.revenuePosting.postedCount', 2);
});

it('blocks billing invoice creation in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeBillingUser();
    $patient = makeBillingPatient();

    $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id))
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks billing invoice update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeBillingUser();
    grantBillingInvoiceUpdateDraftPermission($user);
    $patient = makeBillingPatient();

    $invoice = BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225GUARDB1',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'tax_amount' => 10,
        'total_amount' => 110,
        'paid_amount' => 0,
        'balance_amount' => 110,
        'payment_due_at' => now()->addDays(7)->toDateTimeString(),
        'notes' => 'Guard update target',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$invoice->id, [
            'notes' => 'Attempted guarded update',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks billing invoice status update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $user = makeBillingUser();
    $user->givePermissionTo('billing.invoices.issue');
    $patient = makeBillingPatient();

    $invoice = BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV20260225GUARDB2',
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'tax_amount' => 10,
        'total_amount' => 110,
        'paid_amount' => 0,
        'balance_amount' => 110,
        'payment_due_at' => now()->addDays(7)->toDateTimeString(),
        'notes' => 'Guard status target',
        'status' => 'draft',
        'status_reason' => null,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$invoice->id.'/status', [
            'status' => 'issued',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

/**
 * @return array{0:string,1:string}
 */
function seedBillingPlatformScopeAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedBillingPlatformScopeFacility(
        tenantCode: $tenantCode,
        tenantName: $tenantName,
        countryCode: $countryCode,
        facilityCode: $facilityCode,
        facilityName: $facilityName,
    );

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'billing_officer',
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
function seedBillingPlatformScopeFacility(
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
        'facility_type' => 'billing',
        'timezone' => 'Africa/Nairobi',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}

