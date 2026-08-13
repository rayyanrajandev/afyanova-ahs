<?php

use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Billing\Infrastructure\Models\ConsultationMappingModel;
use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * PricingEngine_Migration_Plan.md Phase 5: Consultation's legacy
 * string-match fallback is gone entirely -- consultationCandidates() now
 * unconditionally prices via the explicit ConsultationMappingModel ->
 * chargeable_item_id path, with no feature flag left to gate it and no
 * legacy price to fall back to. A mapping with no priced chargeable item
 * is genuinely unpriced ('missing_catalog_price'), not a silent
 * string-match rescue.
 */
uses(RefreshDatabase::class);

function makeConsultationCandidateUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('billing.invoices.create');
    $user->givePermissionTo('billing.invoices.read');

    return $user;
}

function makeConsultationCandidatePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Consultation', 'last_name' => 'Candidate', 'gender' => 'female',
        'date_of_birth' => '1990-01-01', 'phone' => '+255700000044', 'country_code' => 'TZ', 'status' => 'active',
    ]);
}

function makeConsultationCandidateClinician(): User
{
    $user = User::factory()->create();
    StaffProfileModel::query()->create([
        'user_id' => $user->id, 'employee_number' => 'EMP'.strtoupper(Str::random(6)),
        'department' => 'General OPD', 'job_title' => 'Clinical Officer', 'license_type' => 'CO',
        'employment_type' => 'full_time', 'status' => 'active',
    ]);

    return $user;
}

function makeConsultationCandidateAppointment(string $patientId, ?int $clinicianUserId = null): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'clinician_user_id' => $clinicianUserId,
        'consultation_owner_user_id' => $clinicianUserId,
        'department' => 'General OPD',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'consultation_started_at' => now()->subMinutes(30)->toDateTimeString(),
        'status' => 'completed',
    ]);
}

function setUpConsultationCandidateMapping(float $price): ConsultationMappingModel
{
    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->fill([
        'catalog_type' => 'consultation', 'charge_model' => 'flat',
        'code' => 'CONSULT-CO-GENERAL-OPD', 'name' => 'CO General OPD Consultation', 'status' => 'active',
    ]);
    $chargeableItem->save();

    PriceBookEntryModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id, 'currency_code' => 'TZS', 'unit_price' => $price, 'status' => 'active',
    ]);

    return ConsultationMappingModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id,
        'clinician_tier' => 'CO',
        'department' => 'General OPD',
    ]);
}

it('consultationCandidates prices via the mapping\'s chargeable item', function (): void {
    setUpConsultationCandidateMapping(22000);
    $patient = makeConsultationCandidatePatient();
    $clinician = makeConsultationCandidateClinician();
    makeConsultationCandidateAppointment($patient->id, $clinician->id);

    $candidate = $this->actingAs(makeConsultationCandidateUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(22000.0)
        ->and($candidate['pricingStatus'])->toBe('priced')
        ->and($candidate['pricingSource'])->toBe('chargeable_item');
});

it('consultationCandidates reports missing_catalog_price when no mapping exists for the tier/department', function (): void {
    // Deliberately no ConsultationMappingModel row.
    $patient = makeConsultationCandidatePatient();
    $clinician = makeConsultationCandidateClinician();
    makeConsultationCandidateAppointment($patient->id, $clinician->id);

    $candidate = $this->actingAs(makeConsultationCandidateUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(0.0)
        ->and($candidate['pricingStatus'])->toBe('missing_catalog_price')
        ->and($candidate['pricingSource'])->toBeNull();
});

it('consultationCandidates reports missing_catalog_price when the mapping exists but has no active price', function (): void {
    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->fill([
        'catalog_type' => 'consultation', 'charge_model' => 'flat',
        'code' => 'CONSULT-CO-GENERAL-OPD', 'name' => 'CO General OPD Consultation', 'status' => 'active',
    ]);
    $chargeableItem->save();
    // Deliberately no PriceBookEntryModel row.
    ConsultationMappingModel::query()->create([
        'chargeable_item_id' => $chargeableItem->id,
        'clinician_tier' => 'CO',
        'department' => 'General OPD',
    ]);

    $patient = makeConsultationCandidatePatient();
    $clinician = makeConsultationCandidateClinician();
    makeConsultationCandidateAppointment($patient->id, $clinician->id);

    $candidate = $this->actingAs(makeConsultationCandidateUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(0.0)
        ->and($candidate['pricingStatus'])->toBe('missing_catalog_price');
});

/**
 * Regression for a real production case: a consultation "owned" by a user
 * with no staff_profiles row (e.g. an admin account) at a facility whose
 * pricing IS fully configured. Must not collapse into 'missing_catalog_price'
 * — that would send whoever's troubleshooting to check the (correctly
 * configured) price book for a gap that isn't there.
 */
it('consultationCandidates reports missing_clinician_tier, not missing_catalog_price, when the consultation owner has no staff profile', function (): void {
    setUpConsultationCandidateMapping(22000);
    $patient = makeConsultationCandidatePatient();
    $nonClinicalOwner = User::factory()->create();
    makeConsultationCandidateAppointment($patient->id, $nonClinicalOwner->id);

    $candidate = $this->actingAs(makeConsultationCandidateUser())
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS')
        ->assertOk()
        ->json('data.0');

    expect((float) $candidate['unitPrice'])->toBe(0.0)
        ->and($candidate['pricingStatus'])->toBe('missing_clinician_tier')
        ->and($candidate['pricingSource'])->toBeNull();
});
