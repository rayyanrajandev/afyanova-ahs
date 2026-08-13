<?php

use App\Models\SystemSetting;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentAuditLogModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeConsultPatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Fatuma',
        'last_name' => 'Juma',
        'middle_name' => null,
        'gender' => 'female',
        'date_of_birth' => '1992-06-15',
        'phone' => '+255711000001',
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

function makeConsultUser(): User
{
    $user = User::factory()->create();
    foreach ([
        'appointments.create',
        'appointments.read',
        'appointments.update',
        'appointments.update-status',
        'billing.invoices.create',
        'billing.invoices.read',
    ] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function makeConsultTariff(string $serviceCode = 'CONSULT-OPD', float $basePrice = 10000.0, string $serviceType = 'consultation'): BillingServiceCatalogItemModel
{
    return BillingServiceCatalogItemModel::query()->create([
        'service_code' => $serviceCode,
        'service_name' => 'OPD Consultation',
        'service_type' => $serviceType,
        'department' => 'Outpatient',
        'unit' => 'visit',
        'base_price' => $basePrice,
        'currency_code' => 'TZS',
        'tax_rate_percent' => 0,
        'is_taxable' => false,
        'effective_from' => now()->subYear()->toDateTimeString(),
        'effective_to' => null,
        'description' => 'Standard consultation tariff',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);
}

function makeConsultFacility(): FacilityModel
{
    $tenant = TenantModel::query()->create([
        'code' => 'T'.strtoupper(Str::random(6)),
        'name' => 'Consultation Test Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    return FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => 'F'.strtoupper(Str::random(6)),
        'name' => 'Consultation Test Facility',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);
}

function makeCompletedAppointment(string $patientId, ?string $facilityId = null, array $overrides = []): AppointmentModel
{
    return AppointmentModel::query()->create(array_merge([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'tenant_id' => null,
        'facility_id' => $facilityId,
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subDays(2)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Malaria',
        'notes' => null,
        'appointment_type' => 'scheduled',
        'consultation_type' => 'new',
        'consultation_type_source' => 'auto',
        'consultation_type_override_reason' => null,
        'prior_completed_appointment_id' => null,
        'status' => 'completed',
        'status_reason' => null,
    ], $overrides));
}

function setConsultationPolicy(array $settings, ?string $facilityId = null): void
{
    foreach ($settings as $key => $value) {
        $configKey = config("consultation_policy.system_settings_keys.{$key}");
        if ($configKey === null) {
            continue;
        }

        SystemSetting::query()->updateOrCreate(
            ['facility_id' => $facilityId, 'key' => $configKey],
            ['group' => 'consultation', 'value' => (string) $value, 'type' => 'string'],
        );
    }
}

// ---------------------------------------------------------------------------
// 1. New patient consultation (first visit ever)
// ---------------------------------------------------------------------------

it('classifies a first-time visit as NEW', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();
    $facilityId = Str::uuid()->toString();

    $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
            'reason' => 'Headache',
            'department' => 'Outpatient',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.consultationType', 'new')
        ->assertJsonPath('data.consultationTypeSource', 'auto');
});

// ---------------------------------------------------------------------------
// 2. Returning patient within follow-up window → REVIEW
// ---------------------------------------------------------------------------

it('classifies a return visit within the follow-up window as REVIEW', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();

    // Simulate a facility ID in scope
    config(['consultation_policy.follow_up_days' => 14]);

    // Create a prior completed appointment at the same facility 3 days ago
    $facilityId = null; // no multi-facility scoping in test
    $prior = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'tenant_id' => null,
        'facility_id' => $facilityId,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subDays(3)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Malaria',
        'notes' => null,
        'appointment_type' => 'scheduled',
        'consultation_type' => 'new',
        'consultation_type_source' => 'auto',
        'consultation_type_override_reason' => null,
        'prior_completed_appointment_id' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);

    $response = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
            'reason' => 'Malaria follow-up',
            'department' => 'Outpatient',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.consultationType', 'review')
        ->assertJsonPath('data.consultationTypeSource', 'auto')
        ->assertJsonPath('data.priorCompletedAppointmentId', $prior->id);
});

// ---------------------------------------------------------------------------
// 3. Returning patient AFTER follow-up window → NEW
// ---------------------------------------------------------------------------

it('classifies a return visit after the follow-up window as NEW', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();

    // Set a short window of 3 days
    config(['consultation_policy.follow_up_days' => 3]);

    // Prior appointment 10 days ago (outside 3-day window)
    AppointmentModel::query()->create([
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subDays(10)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Malaria',
        'notes' => null,
        'appointment_type' => 'scheduled',
        'consultation_type' => 'new',
        'consultation_type_source' => 'auto',
        'consultation_type_override_reason' => null,
        'prior_completed_appointment_id' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);

    $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
            'reason' => 'Malaria check',
            'department' => 'Outpatient',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.consultationType', 'new');
});

// ---------------------------------------------------------------------------
// 4. Same complaint review (same_complaint_required = true)
// ---------------------------------------------------------------------------

it('classifies as REVIEW when complaints overlap and same_complaint_required is true', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();

    config([
        'consultation_policy.follow_up_days' => 14,
        'consultation_policy.same_complaint_required' => true,
    ]);

    AppointmentModel::query()->create([
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subDays(3)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Malaria fever',
        'notes' => null,
        'appointment_type' => 'scheduled',
        'consultation_type' => 'new',
        'consultation_type_source' => 'auto',
        'consultation_type_override_reason' => null,
        'prior_completed_appointment_id' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);

    $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
            'reason' => 'Malaria review',
            'department' => 'Outpatient',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.consultationType', 'review');
});

// ---------------------------------------------------------------------------
// 5. Different complaint → NEW when same_complaint_required = true
// ---------------------------------------------------------------------------

it('classifies as NEW when complaints do not match and same_complaint_required is true', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();

    config([
        'consultation_policy.follow_up_days' => 14,
        'consultation_policy.same_complaint_required' => true,
    ]);

    AppointmentModel::query()->create([
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subDays(3)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Malaria',
        'notes' => null,
        'appointment_type' => 'scheduled',
        'consultation_type' => 'new',
        'consultation_type_source' => 'auto',
        'consultation_type_override_reason' => null,
        'prior_completed_appointment_id' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);

    $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
            'reason' => 'Eye problem',
            'department' => 'Outpatient',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.consultationType', 'new');
});

// ---------------------------------------------------------------------------
// 6. Billing: free follow-up for REVIEW consultation
// ---------------------------------------------------------------------------

it('applies zero consultation fee for a REVIEW visit when review_fee_is_free is true', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();

    config([
        'consultation_policy.follow_up_days' => 14,
        'consultation_policy.review_fee_is_free' => true,
    ]);

    $tariff = makeConsultTariff('CONSULT-OPD', 10000.0);

    $priorAppointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subDays(2)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Malaria',
        'notes' => null,
        'appointment_type' => 'scheduled',
        'consultation_type' => 'new',
        'consultation_type_source' => 'auto',
        'consultation_type_override_reason' => null,
        'prior_completed_appointment_id' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);

    // Create the review appointment
    $apptResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
            'reason' => 'Malaria review',
            'department' => 'Outpatient',
        ]);

    $apptResponse->assertStatus(201)->assertJsonPath('data.consultationType', 'review');
    $appointmentId = $apptResponse->json('data.id');

    // Create invoice for the review appointment
    $invoiceResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/billing-invoices', [
            'patientId' => $patient->id,
            'appointmentId' => $appointmentId,
            'invoiceDate' => now()->toDateString(),
            'currencyCode' => 'TZS',
            'subtotalAmount' => 10000,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'autoPriceLineItems' => true,
            'lineItems' => [
                [
                    'description' => 'OPD Consultation',
                    'quantity' => 1,
                    'unitPrice' => 10000,
                    'serviceCode' => $tariff->service_code,
                    'sourceWorkflowKind' => 'appointment_consultation',
                    'sourceWorkflowId' => $appointmentId,
                ],
            ],
        ]);

    $invoiceResponse->assertStatus(201);

    // The review discount should make total = 0 (free)
    $pricingContext = $invoiceResponse->json('data.pricingContext');
    expect($pricingContext['consultationReviewDiscount']['applied'])->toBeTrue();
    expect($pricingContext['consultationReviewDiscount']['isFreeFollowUp'])->toBeTrue();
    expect((float) $invoiceResponse->json('data.totalAmount'))->toBe(0.0);
});

// ---------------------------------------------------------------------------
// 7. Billing: discounted review fee (50%)
// ---------------------------------------------------------------------------

it('applies 50% discount on consultation fee for a REVIEW visit', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();

    config([
        'consultation_policy.follow_up_days' => 14,
        'consultation_policy.review_fee_is_free' => false,
        'consultation_policy.review_fee_percentage' => 50.0,
    ]);

    $tariff = makeConsultTariff('CONSULT-OPD-50', 10000.0);

    AppointmentModel::query()->create([
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subDays(2)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Malaria',
        'notes' => null,
        'appointment_type' => 'scheduled',
        'consultation_type' => 'new',
        'consultation_type_source' => 'auto',
        'consultation_type_override_reason' => null,
        'prior_completed_appointment_id' => null,
        'status' => 'completed',
        'status_reason' => null,
    ]);

    $apptResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
            'reason' => 'Malaria review',
            'department' => 'Outpatient',
        ]);

    $apptResponse->assertStatus(201)->assertJsonPath('data.consultationType', 'review');
    $appointmentId = $apptResponse->json('data.id');

    $invoiceResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/billing-invoices', [
            'patientId' => $patient->id,
            'appointmentId' => $appointmentId,
            'invoiceDate' => now()->toDateString(),
            'currencyCode' => 'TZS',
            'subtotalAmount' => 10000,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'autoPriceLineItems' => true,
            'lineItems' => [
                [
                    'description' => 'OPD Consultation',
                    'quantity' => 1,
                    'unitPrice' => 10000,
                    'serviceCode' => $tariff->service_code,
                    'sourceWorkflowKind' => 'appointment_consultation',
                    'sourceWorkflowId' => $appointmentId,
                ],
            ],
        ]);

    $invoiceResponse->assertStatus(201);

    $pricingContext = $invoiceResponse->json('data.pricingContext');
    expect($pricingContext['consultationReviewDiscount']['applied'])->toBeTrue();
    expect((float) $pricingContext['consultationReviewDiscount']['discountPercent'])->toBe(50.0);
    // Total should be 10000 - 5000 = 5000
    expect((float) $invoiceResponse->json('data.totalAmount'))->toBe(5000.0);
});

it('treats review_fee_percentage as the charged percentage, including 0% free reviews', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();

    config([
        'consultation_policy.review_fee_is_free' => false,
        'consultation_policy.review_fee_percentage' => 0.0,
    ]);

    $tariff = makeConsultTariff('CONSULT-OPD-FREE-PCT', 10000.0);
    $prior = makeCompletedAppointment($patient->id);
    $review = makeCompletedAppointment($patient->id, null, [
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'reason' => 'Malaria review',
        'consultation_type' => 'review',
        'prior_completed_appointment_id' => $prior->id,
        'status' => 'waiting_provider',
    ]);

    $invoiceResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/billing-invoices', [
            'patientId' => $patient->id,
            'appointmentId' => $review->id,
            'invoiceDate' => now()->toDateString(),
            'currencyCode' => 'TZS',
            'subtotalAmount' => 10000,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'autoPriceLineItems' => true,
            'lineItems' => [[
                'description' => 'OPD Consultation',
                'quantity' => 1,
                'unitPrice' => 10000,
                'serviceCode' => $tariff->service_code,
                'sourceWorkflowKind' => 'appointment_consultation',
                'sourceWorkflowId' => $review->id,
            ]],
        ]);

    $invoiceResponse->assertStatus(201);
    expect((float) $invoiceResponse->json('data.totalAmount'))->toBe(0.0);
    expect((float) $invoiceResponse->json('data.consultationReviewDiscount.reviewFeePercentage'))->toBe(0.0);
    expect((float) $invoiceResponse->json('data.consultationReviewDiscount.discountPercent'))->toBe(100.0);
});

it('applies consultation review pricing to invoice previews', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();

    config([
        'consultation_policy.review_fee_is_free' => false,
        'consultation_policy.review_fee_percentage' => 25.0,
    ]);

    $tariff = makeConsultTariff('CONSULT-OPD-PREVIEW', 10000.0);
    $prior = makeCompletedAppointment($patient->id);
    $review = makeCompletedAppointment($patient->id, null, [
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'reason' => 'Malaria review',
        'consultation_type' => 'review',
        'prior_completed_appointment_id' => $prior->id,
        'status' => 'waiting_provider',
    ]);

    $previewResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/billing-invoices/preview', [
            'patientId' => $patient->id,
            'appointmentId' => $review->id,
            'invoiceDate' => now()->toDateString(),
            'currencyCode' => 'TZS',
            'subtotalAmount' => 10000,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'autoPriceLineItems' => true,
            'lineItems' => [[
                'description' => 'OPD Consultation',
                'quantity' => 1,
                'unitPrice' => 10000,
                'serviceCode' => $tariff->service_code,
                'sourceWorkflowKind' => 'appointment_consultation',
                'sourceWorkflowId' => $review->id,
            ]],
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.totalAmount', 2500);

    expect((float) $previewResponse->json('data.consultationReviewDiscount.reviewFeePercentage'))->toBe(25.0);
    expect((float) $previewResponse->json('data.consultationReviewDiscount.discountPercent'))->toBe(75.0);
});

it('reuses a draft and avoids duplicate consultation charges for the same appointment source', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();

    config([
        'consultation_policy.review_fee_is_free' => false,
        'consultation_policy.review_fee_percentage' => 50.0,
    ]);

    $tariff = makeConsultTariff('CONSULT-OPD-NO-DUPE', 10000.0);
    $prior = makeCompletedAppointment($patient->id);
    $review = makeCompletedAppointment($patient->id, null, [
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'reason' => 'Malaria review',
        'consultation_type' => 'review',
        'prior_completed_appointment_id' => $prior->id,
        'status' => 'waiting_provider',
    ]);

    $payload = [
        'patientId' => $patient->id,
        'appointmentId' => $review->id,
        'invoiceDate' => now()->toDateString(),
        'currencyCode' => 'TZS',
        'subtotalAmount' => 10000,
        'discountAmount' => 0,
        'taxAmount' => 0,
        'autoPriceLineItems' => true,
        'lineItems' => [[
            'description' => 'OPD Consultation',
            'quantity' => 1,
            'unitPrice' => 10000,
            'serviceCode' => $tariff->service_code,
            'sourceWorkflowKind' => 'appointment_consultation',
            'sourceWorkflowId' => $review->id,
        ]],
    ];

    $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/billing-invoices', $payload)
        ->assertStatus(201);

    $secondResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/billing-invoices', $payload);

    $secondResponse->assertStatus(200)
        ->assertJsonPath('meta.draftReused', true)
        ->assertJsonCount(1, 'data.lineItems');

    expect((float) $secondResponse->json('data.totalAmount'))->toBe(5000.0);
});

// ---------------------------------------------------------------------------
// 8. Manual override with reason → stored and audited
// ---------------------------------------------------------------------------

it('allows staff to override consultation type from NEW to REVIEW with a reason', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();

    config(['consultation_policy.follow_up_days' => 14]);

    // Create a new appointment (no prior → auto NEW)
    $apptResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
            'reason' => 'Hypertension',
            'department' => 'Outpatient',
        ]);

    $apptResponse->assertStatus(201)->assertJsonPath('data.consultationType', 'new');
    $appointmentId = $apptResponse->json('data.id');

    // Staff overrides to REVIEW with a reason
    $overrideResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->patchJson("/api/v1/appointments/{$appointmentId}/consultation-type", [
            'consultationType' => 'review',
            'consultationTypeOverrideReason' => 'Patient self-presented for hypertension review within window per clinician.',
        ]);

    $overrideResponse->assertStatus(200)
        ->assertJsonPath('data.consultationType', 'review')
        ->assertJsonPath('data.consultationTypeSource', 'manual');

    $reason = $overrideResponse->json('data.consultationTypeOverrideReason');
    expect($reason)->not->toBeNull();

    // Audit log should record the override
    $auditLog = AppointmentAuditLogModel::query()
        ->where('appointment_id', $appointmentId)
        ->where('action', 'appointment.consultation_type.overridden')
        ->first();

    expect($auditLog)->not->toBeNull();
    $changes = $auditLog->changes ?? [];
    expect($changes['consultation_type']['before'] ?? null)->toBe('new');
    expect($changes['consultation_type']['after'] ?? null)->toBe('review');
});

// ---------------------------------------------------------------------------
// 9. Override without reason → rejected
// ---------------------------------------------------------------------------

it('rejects a consultation type override without a reason', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();

    $apptResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
            'reason' => 'Cough',
            'department' => 'Outpatient',
        ]);

    $apptResponse->assertStatus(201);
    $appointmentId = $apptResponse->json('data.id');

    $this->withoutMiddleware()
        ->actingAs($user)
        ->patchJson("/api/v1/appointments/{$appointmentId}/consultation-type", [
            'consultationType' => 'review',
            // No reason provided
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.consultationTypeOverrideReason.0', 'A reason is required when manually overriding the consultation type.');
});

// ---------------------------------------------------------------------------
// 10. Billing: NEW consultation receives no review discount
// ---------------------------------------------------------------------------

it('does not apply any review discount when appointment type is NEW', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();

    config(['consultation_policy.review_fee_percentage' => 50.0]);

    $tariff = makeConsultTariff('CONSULT-NEW-ONLY', 10000.0);

    // No prior completed appointment → will be classified NEW
    $apptResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->id,
            'scheduledAt' => now()->addDay()->toDateTimeString(),
            'durationMinutes' => 30,
            'reason' => 'Fever',
            'department' => 'Outpatient',
        ]);

    $apptResponse->assertStatus(201)->assertJsonPath('data.consultationType', 'new');
    $appointmentId = $apptResponse->json('data.id');

    $invoiceResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/billing-invoices', [
            'patientId' => $patient->id,
            'appointmentId' => $appointmentId,
            'invoiceDate' => now()->toDateString(),
            'currencyCode' => 'TZS',
            'subtotalAmount' => 10000,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'autoPriceLineItems' => true,
            'lineItems' => [
                [
                    'description' => 'OPD Consultation',
                    'quantity' => 1,
                    'unitPrice' => 10000,
                    'serviceCode' => $tariff->service_code,
                    'sourceWorkflowKind' => 'appointment_consultation',
                    'sourceWorkflowId' => $appointmentId,
                ],
            ],
        ]);

    $invoiceResponse->assertStatus(201);

    $pricingContext = $invoiceResponse->json('data.pricingContext');
    // Review discount should NOT be applied
    expect($pricingContext['consultationReviewDiscount']['applied'] ?? null)->toBeFalsy();
    // Full fee
    expect((float) $invoiceResponse->json('data.totalAmount'))->toBe(10000.0);
});

// ---------------------------------------------------------------------------
// 11. Facility-level policy override via SystemSetting
// ---------------------------------------------------------------------------

it('uses facility-level SystemSetting for follow_up_days when available', function (): void {
    $facilityId = Str::uuid()->toString();

    // Global config says 14 days but facility says 1 day
    config(['consultation_policy.follow_up_days' => 14]);
    setConsultationPolicy(['follow_up_days' => 1], $facilityId);

    $policy = app(\App\Modules\Appointment\Application\Support\ConsultationReviewPolicyResolver::class)
        ->resolve($facilityId);

    expect($policy['follow_up_days'])->toBe(1);
});

it('falls back to global SystemSetting policy when facility scoped settings are not available in the schema', function (): void {
    $settingKey = config('consultation_policy.system_settings_keys.follow_up_days');

    SystemSetting::query()->create([
        'group' => 'consultation',
        'key' => $settingKey,
        'value' => '3',
        'type' => 'string',
    ]);

    Schema::shouldReceive('hasTable')
        ->once()
        ->with('system_settings')
        ->andReturn(true);
    Schema::shouldReceive('hasColumn')
        ->once()
        ->with('system_settings', 'facility_id')
        ->andReturn(false);

    $policy = app(\App\Modules\Appointment\Application\Support\ConsultationReviewPolicyResolver::class)
        ->resolve(Str::uuid()->toString());

    expect($policy['follow_up_days'])->toBe(3);
});

it('uses the linked appointment facility policy when pricing REVIEW consultations', function (): void {
    $user = makeConsultUser();
    $patient = makeConsultPatient();
    $facility = makeConsultFacility();

    config([
        'consultation_policy.review_fee_is_free' => false,
        'consultation_policy.review_fee_percentage' => 50.0,
    ]);
    setConsultationPolicy([
        'review_fee_is_free' => false,
        'review_fee_percentage' => 25.0,
    ], $facility->id);

    $tariff = makeConsultTariff('CONSULT-OPD-FACILITY-POLICY', 10000.0);
    $prior = makeCompletedAppointment($patient->id, $facility->id, [
        'tenant_id' => $facility->tenant_id,
    ]);
    $review = makeCompletedAppointment($patient->id, $facility->id, [
        'tenant_id' => $facility->tenant_id,
        'appointment_number' => 'APT'.strtoupper(Str::random(8)),
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'reason' => 'Malaria review',
        'consultation_type' => 'review',
        'prior_completed_appointment_id' => $prior->id,
        'status' => 'waiting_provider',
    ]);

    $invoiceResponse = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v1/billing-invoices', [
            'patientId' => $patient->id,
            'appointmentId' => $review->id,
            'invoiceDate' => now()->toDateString(),
            'currencyCode' => 'TZS',
            'subtotalAmount' => 10000,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'autoPriceLineItems' => true,
            'lineItems' => [[
                'description' => 'OPD Consultation',
                'quantity' => 1,
                'unitPrice' => 10000,
                'serviceCode' => $tariff->service_code,
                'sourceWorkflowKind' => 'appointment_consultation',
                'sourceWorkflowId' => $review->id,
            ]],
        ]);

    $invoiceResponse->assertStatus(201);
    expect((float) $invoiceResponse->json('data.totalAmount'))->toBe(2500.0);
    expect((float) $invoiceResponse->json('data.consultationReviewDiscount.reviewFeePercentage'))->toBe(25.0);
    expect((float) $invoiceResponse->json('data.consultationReviewDiscount.discountPercent'))->toBe(75.0);
});
