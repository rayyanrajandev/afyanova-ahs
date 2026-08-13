<?php

use App\Models\User;
use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Appointment\Application\UseCases\UpdateAppointmentStatusUseCase;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\ConsultationMappingModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileSpecialtyModel;
use App\Modules\Staff\Infrastructure\Models\StaffRegulatoryProfileModel;
use App\Modules\Staff\Infrastructure\Models\ClinicalSpecialtyModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    $this->withoutMiddleware(EnsureFacilitySubscriptionEntitlement::class);
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
});

function makeAutoCapturePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-'.strtoupper(Str::random(8)),
        'first_name' => 'Auto',
        'last_name' => 'Capture',
        'gender' => 'female',
        'date_of_birth' => '1990-01-15',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function makeAutoCaptureClinician(): User
{
    $user = User::factory()->create();

    $profile = StaffProfileModel::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'DOC-'.strtoupper(Str::random(6)),
        'department' => 'Outpatient',
        'job_title' => 'Medical Doctor',
        'license_type' => 'MD',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    StaffRegulatoryProfileModel::query()->create([
        'staff_profile_id' => $profile->id,
        'primary_regulator_code' => 'TZ-MEDICAL-BOARD',
        'cadre_code' => 'MD',
        'professional_title' => 'Medical Doctor',
        'registration_type' => 'full',
        'practice_authority_level' => 'full',
        'supervision_level' => 'none',
        'good_standing_status' => 'active',
    ]);

    $specialty = ClinicalSpecialtyModel::query()->create([
        'code' => 'GENERAL',
        'name' => 'General Medicine',
        'status' => 'active',
    ]);

    StaffProfileSpecialtyModel::query()->create([
        'staff_profile_id' => $profile->id,
        'specialty_id' => $specialty->id,
        'is_primary' => true,
    ]);

    return $user;
}

/**
 * PricingEngine_Migration_Plan.md Phase 5: Consultation's legacy
 * string-match fallback is gone -- auto-capture only ever prices via an
 * explicit ConsultationMappingModel row (tier + department -> chargeable
 * item), so this fixture builds that instead of a standalone legacy tariff.
 */
function makeAutoCaptureConsultationTariff(): ConsultationMappingModel
{
    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->fill([
        'catalog_type' => 'consultation',
        'charge_model' => 'flat',
        'code' => 'CONSULT-MD-OUTPATIENT',
        'name' => 'Medical Doctor Consultation - Outpatient',
        'status' => 'active',
    ]);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id,
        'currency_code' => 'TZS',
        'unit_price' => 35000,
        'status' => 'active',
    ]);

    return ConsultationMappingModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id,
        'clinician_tier' => 'MD',
        'department' => 'Outpatient',
    ]);
}

function makeAutoCaptureAppointment(string $patientId, int $clinicianUserId, array $overrides = []): AppointmentModel
{
    return AppointmentModel::query()->create(array_merge([
        'appointment_number' => 'APT-'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'clinician_user_id' => $clinicianUserId,
        'consultation_owner_user_id' => $clinicianUserId,
        'department' => 'Outpatient',
        'status' => 'waiting_provider',
        'scheduled_at' => now()->subHours(2),
        'checked_in_at' => now()->subHours(1)->toDateTimeString(),
        'triaged_at' => now()->subMinutes(45)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'General checkup',
        'encounter_started_at' => now()->subMinutes(45)->toDateTimeString(),
        'consultation_started_at' => now()->subMinutes(30)->toDateTimeString(),
    ], $overrides));
}

function executeStatusTransition(string $appointmentId, string $status, ?int $actorId = null): array
{
    $useCase = app(UpdateAppointmentStatusUseCase::class);
    $result = $useCase->execute(
        id: $appointmentId,
        status: $status,
        reason: null,
        actorId: $actorId,
    );

    return [
        'appointment' => $result,
        'autoCapture' => $useCase->getLastAutoCaptureResult(),
    ];
}

it('auto-captures consultation fee when appointment status becomes in_consultation', function (): void {
    $user = makeAutoCaptureClinician();
    $patient = makeAutoCapturePatient();
    $tariff = makeAutoCaptureConsultationTariff();
    $appointment = makeAutoCaptureAppointment($patient->id, $user->id);

    $result = executeStatusTransition($appointment->id, 'in_consultation', $user->id);

    $invoices = BillingInvoiceModel::query()
        ->where('patient_id', $patient->id)
        ->where('appointment_id', $appointment->id)
        ->get();

    expect($invoices)->toHaveCount(1);

    $invoice = $invoices->first();
    expect($invoice->status)->toBe('draft');

    $lineItems = $invoice->line_items ?? [];
    expect($lineItems)->toHaveCount(1);
    expect($lineItems[0]['sourceWorkflowKind'])->toBe('appointment_consultation');
    expect($lineItems[0]['sourceWorkflowId'])->toBe((string) $appointment->id);
    expect((float) $invoice->total_amount)->toBe(35000.0);
    expect((float) $invoice->balance_amount)->toBe(35000.0);
});

it('does not duplicate consultation invoice on second in_consultation transition', function (): void {
    $user = makeAutoCaptureClinician();
    $patient = makeAutoCapturePatient();
    $tariff = makeAutoCaptureConsultationTariff();
    $appointment = makeAutoCaptureAppointment($patient->id, $user->id);

    executeStatusTransition($appointment->id, 'in_consultation', $user->id);
    executeStatusTransition($appointment->id, 'in_consultation', $user->id);

    $invoices = BillingInvoiceModel::query()
        ->where('patient_id', $patient->id)
        ->where('appointment_id', $appointment->id)
        ->get();

    expect($invoices)->toHaveCount(1);
});

it('creates draft invoice with correct consultation service name for MD clinician', function (): void {
    $user = makeAutoCaptureClinician();
    $patient = makeAutoCapturePatient();
    $tariff = makeAutoCaptureConsultationTariff();
    $appointment = makeAutoCaptureAppointment($patient->id, $user->id);

    executeStatusTransition($appointment->id, 'in_consultation', $user->id);

    $invoice = BillingInvoiceModel::query()
        ->where('patient_id', $patient->id)
        ->where('appointment_id', $appointment->id)
        ->first();

    expect($invoice)->not->toBeNull();

    $lineItem = $invoice->line_items[0] ?? [];
    expect($lineItem['description'] ?? '')->toContain('Consultation');
});

it('does not auto-capture when appointment transitions to non-in_consultation status', function (): void {
    $user = makeAutoCaptureClinician();
    $patient = makeAutoCapturePatient();
    $tariff = makeAutoCaptureConsultationTariff();
    $appointment = makeAutoCaptureAppointment($patient->id, $user->id);

    executeStatusTransition($appointment->id, 'cancelled', $user->id);

    $invoices = BillingInvoiceModel::query()
        ->where('patient_id', $patient->id)
        ->where('appointment_id', $appointment->id)
        ->get();

    expect($invoices)->toHaveCount(0);
});

it('does not auto-capture at TZS 0 when no catalog pricing resolves for tier or department', function (): void {
    $user = makeAutoCaptureClinician();
    $patient = makeAutoCapturePatient();
    $appointment = makeAutoCaptureAppointment($patient->id, $user->id);

    $result = executeStatusTransition($appointment->id, 'in_consultation', $user->id);

    expect($result['autoCapture']['captured'] ?? null)->toBeFalse();
    expect($result['autoCapture']['reason'] ?? null)->toBe('no_catalog_price');

    $invoices = BillingInvoiceModel::query()
        ->where('patient_id', $patient->id)
        ->where('appointment_id', $appointment->id)
        ->get();

    // No catalog price could be resolved for this visit — leaving it
    // uncaptured (rather than invoicing at TZS 0) means it still surfaces
    // as a pending, correctly-priced-as-"missing" candidate via
    // ListBillingChargeCaptureCandidatesUseCase instead of looking resolved.
    expect($invoices)->toHaveCount(0);
});

it('auto-capture does not break, and does not invoice at TZS 0, when clinician has no staff profile', function (): void {
    $patient = makeAutoCapturePatient();
    $tariff = makeAutoCaptureConsultationTariff();

    $noProfileUser = User::factory()->create();

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'clinician_user_id' => $noProfileUser->id,
        'department' => 'Outpatient',
        'status' => 'waiting_provider',
        'scheduled_at' => now()->subHours(2),
        'triaged_at' => now()->subMinutes(45)->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'General checkup',
    ]);

    $result = executeStatusTransition($appointment->id, 'in_consultation', $noProfileUser->id);

    // The seeded tariff is tier-specific (CONSULT-MD-OUTPATIENT); a clinician
    // with no staff profile resolves no tier, so no code this visit tries
    // matches it. Confirms the use case still completes cleanly (no
    // exception) rather than crashing — it just leaves the visit uncaptured.
    // Reason is 'no_clinician_tier', distinct from 'no_catalog_price' —
    // pricing here is fully configured (makeAutoCaptureConsultationTariff())
    // and was never even attempted, so this must not look like a pricing gap.
    expect($result['autoCapture']['captured'] ?? null)->toBeFalse();
    expect($result['autoCapture']['reason'] ?? null)->toBe('no_clinician_tier');

    $invoices = BillingInvoiceModel::query()
        ->where('patient_id', $patient->id)
        ->where('appointment_id', $appointment->id)
        ->get();

    expect($invoices)->toHaveCount(0);
});
