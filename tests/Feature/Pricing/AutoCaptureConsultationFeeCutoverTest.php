<?php

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Appointment\Application\UseCases\UpdateAppointmentStatusUseCase;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\ConsultationMappingModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Staff\Infrastructure\Models\ClinicalSpecialtyModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileSpecialtyModel;
use App\Modules\Staff\Infrastructure\Models\StaffRegulatoryProfileModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * PricingEngine_Migration_Plan.md Phase 5: auto-capture's legacy
 * CONSULT-{tier}-{dept} string-generation fallback is gone entirely --
 * this is now unconditional on the mapping -> chargeable_item path, no
 * flag left to gate it.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    $this->withoutMiddleware(EnsureFacilitySubscriptionEntitlement::class);
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
});

function makeAutoCaptureCutoverPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-'.strtoupper(Str::random(8)),
        'first_name' => 'Cutover', 'last_name' => 'Test', 'gender' => 'male',
        'date_of_birth' => '1990-01-15', 'country_code' => 'TZ', 'status' => 'active',
    ]);
}

function makeAutoCaptureCutoverClinician(): User
{
    $user = User::factory()->create();

    $profile = StaffProfileModel::query()->create([
        'user_id' => $user->id, 'employee_number' => 'DOC-'.strtoupper(Str::random(6)),
        'department' => 'Outpatient', 'job_title' => 'Clinical Officer', 'license_type' => 'CO',
        'employment_type' => 'full_time', 'status' => 'active',
    ]);

    StaffRegulatoryProfileModel::query()->create([
        'staff_profile_id' => $profile->id, 'primary_regulator_code' => 'TZ-CO-BOARD',
        'cadre_code' => 'CO', 'professional_title' => 'Clinical Officer', 'registration_type' => 'full',
        'practice_authority_level' => 'full', 'supervision_level' => 'none', 'good_standing_status' => 'active',
    ]);

    $specialty = ClinicalSpecialtyModel::query()->create(['code' => 'GENERAL-CUTOVER', 'name' => 'General', 'status' => 'active']);
    StaffProfileSpecialtyModel::query()->create(['staff_profile_id' => $profile->id, 'specialty_id' => $specialty->id, 'is_primary' => true]);

    return $user;
}

function makeAutoCaptureCutoverAppointment(string $patientId, int $clinicianUserId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT-'.strtoupper(Str::random(8)),
        'patient_id' => $patientId, 'clinician_user_id' => $clinicianUserId, 'consultation_owner_user_id' => $clinicianUserId,
        'department' => 'General OPD', 'status' => 'waiting_provider',
        'scheduled_at' => now()->subHours(2), 'checked_in_at' => now()->subHours(1)->toDateTimeString(),
        'triaged_at' => now()->subMinutes(45)->toDateTimeString(), 'duration_minutes' => 30, 'reason' => 'Checkup',
        'encounter_started_at' => now()->subMinutes(45)->toDateTimeString(),
        'consultation_started_at' => now()->subMinutes(30)->toDateTimeString(),
    ]);
}

function setUpAutoCaptureCutoverMapping(float $price): void
{
    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->fill([
        'catalog_type' => 'consultation', 'charge_model' => 'flat',
        'code' => 'CONSULT-CO-GENERAL-OPD-CUTOVER', 'name' => 'CO General OPD Consultation', 'status' => 'active',
    ]);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id, 'currency_code' => 'TZS', 'unit_price' => $price, 'status' => 'active',
    ]);

    ConsultationMappingModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id,
        'clinician_tier' => 'CO',
        'department' => 'General OPD',
    ]);
}

function executeAutoCaptureCutoverTransition(string $appointmentId): void
{
    app(UpdateAppointmentStatusUseCase::class)->execute(id: $appointmentId, status: 'in_consultation', reason: null, actorId: null);
}

it('auto-capture prices via the mapping\'s chargeable item', function (): void {
    setUpAutoCaptureCutoverMapping(20000);
    $patient = makeAutoCaptureCutoverPatient();
    $clinician = makeAutoCaptureCutoverClinician();
    $appointment = makeAutoCaptureCutoverAppointment($patient->id, $clinician->id);

    executeAutoCaptureCutoverTransition($appointment->id);

    $invoice = BillingInvoiceModel::query()->where('patient_id', $patient->id)->first();
    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->subtotal_amount)->toBe(20000.0);
});

it('auto-capture leaves the visit uncaptured when no mapping exists for the tier/department', function (): void {
    $patient = makeAutoCaptureCutoverPatient();
    $clinician = makeAutoCaptureCutoverClinician();
    $appointment = makeAutoCaptureCutoverAppointment($patient->id, $clinician->id);

    executeAutoCaptureCutoverTransition($appointment->id);

    $invoice = BillingInvoiceModel::query()->where('patient_id', $patient->id)->first();
    expect($invoice)->toBeNull();
});

it('auto-capture leaves the visit uncaptured when the mapping has no chargeable_item_id yet', function (): void {
    ConsultationMappingModel::query()->create([
        'clinician_tier' => 'CO',
        'department' => 'General OPD',
    ]);

    $patient = makeAutoCaptureCutoverPatient();
    $clinician = makeAutoCaptureCutoverClinician();
    $appointment = makeAutoCaptureCutoverAppointment($patient->id, $clinician->id);

    executeAutoCaptureCutoverTransition($appointment->id);

    $invoice = BillingInvoiceModel::query()->where('patient_id', $patient->id)->first();
    expect($invoice)->toBeNull();
});

/**
 * Regression for a real production case: a consultation "owned" by a user
 * with no staff_profiles row (e.g. an admin account) at a facility whose
 * pricing IS fully configured (mapping + chargeable item + active price
 * book entry all present, per setUpAutoCaptureCutoverMapping()). Before this
 * fix, both this path and a genuinely-unpriced mapping returned the same
 * 'no_catalog_price' reason, making a clinician-assignment problem look
 * identical to a pricing-admin problem.
 */
it('auto-capture reports no_clinician_tier, not no_catalog_price, when the consultation owner has no staff profile', function (): void {
    setUpAutoCaptureCutoverMapping(20000);
    $patient = makeAutoCaptureCutoverPatient();
    $nonClinicalOwner = User::factory()->create();
    $appointment = makeAutoCaptureCutoverAppointment($patient->id, $nonClinicalOwner->id);

    $useCase = app(UpdateAppointmentStatusUseCase::class);
    $useCase->execute(id: $appointment->id, status: 'in_consultation', reason: null, actorId: null);

    expect(BillingInvoiceModel::query()->where('patient_id', $patient->id)->first())->toBeNull()
        ->and($useCase->getLastAutoCaptureResult())->toMatchArray([
            'captured' => false,
            'reason' => 'no_clinician_tier',
        ]);
});
