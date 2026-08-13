<?php

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use App\Modules\Staff\Infrastructure\Models\StaffCredentialingAuditLogModel;
use App\Modules\Staff\Infrastructure\Models\StaffDocumentModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfessionalRegistrationModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Modules\Staff\Infrastructure\Models\StaffRegulatoryProfileModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeStaffCredentialingActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function makeStaffCredentialingProfile(?string $tenantId = null): StaffProfileModel
{
    $targetUser = User::factory()->create();

    return StaffProfileModel::query()->create([
        'tenant_id' => $tenantId,
        'user_id' => $targetUser->id,
        'employee_number' => 'STF-CRD-'.strtoupper(Str::random(10)),
        'department' => 'Medical',
        'job_title' => 'Medical Officer',
        'professional_license_number' => 'LIC-CRD-'.strtoupper(Str::random(6)),
        'license_type' => 'Medical Officer',
        'phone_extension' => '210',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);
}

function staffRegulatoryProfilePayload(array $overrides = []): array
{
    return array_merge([
        'primaryRegulatorCode' => 'mct',
        'cadreCode' => 'medical_doctor',
        'professionalTitle' => 'Medical Doctor',
        'registrationType' => 'full',
        'practiceAuthorityLevel' => 'independent',
        'supervisionLevel' => 'independent',
        'goodStandingStatus' => 'in_good_standing',
        'goodStandingCheckedAt' => '2026-03-01',
        'notes' => 'Good standing confirmed.',
    ], $overrides);
}

function staffProfessionalRegistrationPayload(?string $sourceDocumentId = null, array $overrides = []): array
{
    return array_merge([
        'regulatorCode' => 'mct',
        'registrationCategory' => 'annual_practicing_license',
        'registrationNumber' => 'MCT-'.strtoupper(Str::random(8)),
        'licenseNumber' => 'APL-'.strtoupper(Str::random(8)),
        'registrationStatus' => 'active',
        'licenseStatus' => 'active',
        'issuedAt' => '2026-01-01',
        'expiresAt' => '2026-12-31',
        'renewalDueAt' => '2026-12-15',
        'cpdCycleStartAt' => '2026-01-01',
        'cpdCycleEndAt' => '2026-12-31',
        'cpdPointsRequired' => 30,
        'cpdPointsEarned' => 12,
        'sourceDocumentId' => $sourceDocumentId,
        'sourceSystem' => 'manual_entry',
        'notes' => 'Imported from facility onboarding checklist.',
    ], $overrides);
}

/**
 * @return array{tenant: TenantModel, facility: FacilityModel}
 */
function makeStaffCredentialingContext(string $tenantCode, string $facilityCode): array
{
    $tenant = TenantModel::query()->create([
        'code' => strtoupper($tenantCode),
        'name' => 'Staff Credentialing Tenant '.strtoupper($tenantCode),
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => strtoupper($facilityCode),
        'name' => 'Staff Credentialing Facility '.strtoupper($facilityCode),
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    return [
        'tenant' => $tenant,
        'facility' => $facility,
    ];
}

/**
 * @return array{0:string,1:string}
 */
function seedStaffCredentialingScopeAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedStaffCredentialingScopeFacility(
        tenantCode: $tenantCode,
        tenantName: $tenantName,
        countryCode: $countryCode,
        facilityCode: $facilityCode,
        facilityName: $facilityName,
    );

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'hr',
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
function seedStaffCredentialingScopeFacility(
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
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}

function makeStaffCredentialingRegulatoryProfile(string $staffProfileId, ?string $tenantId = null, array $overrides = []): StaffRegulatoryProfileModel
{
    return StaffRegulatoryProfileModel::query()->create(array_merge([
        'staff_profile_id' => $staffProfileId,
        'tenant_id' => $tenantId,
        'primary_regulator_code' => 'mct',
        'cadre_code' => 'medical_doctor',
        'professional_title' => 'Medical Doctor',
        'registration_type' => 'full',
        'practice_authority_level' => 'independent',
        'supervision_level' => 'independent',
        'good_standing_status' => 'in_good_standing',
        'good_standing_checked_at' => '2026-03-01',
        'notes' => null,
        'created_by_user_id' => null,
        'updated_by_user_id' => null,
    ], $overrides));
}

function makeStaffCredentialingRegistration(
    string $staffProfileId,
    ?string $tenantId = null,
    ?string $staffRegulatoryProfileId = null,
    array $overrides = []
): StaffProfessionalRegistrationModel {
    return StaffProfessionalRegistrationModel::query()->create(array_merge([
        'staff_profile_id' => $staffProfileId,
        'tenant_id' => $tenantId,
        'staff_regulatory_profile_id' => $staffRegulatoryProfileId,
        'regulator_code' => 'mct',
        'registration_category' => 'annual_practicing_license',
        'registration_number' => 'REG-'.strtoupper(Str::random(8)),
        'license_number' => 'LIC-'.strtoupper(Str::random(8)),
        'registration_status' => 'active',
        'license_status' => 'active',
        'verification_status' => 'verified',
        'verification_reason' => null,
        'verification_notes' => null,
        'verified_at' => now(),
        'verified_by_user_id' => null,
        'issued_at' => '2026-01-01',
        'expires_at' => '2026-12-31',
        'renewal_due_at' => '2026-12-15',
        'cpd_cycle_start_at' => '2026-01-01',
        'cpd_cycle_end_at' => '2026-12-31',
        'cpd_points_required' => 30,
        'cpd_points_earned' => 20,
        'source_document_id' => null,
        'source_system' => 'manual_entry',
        'notes' => null,
        'created_by_user_id' => null,
        'updated_by_user_id' => null,
    ], $overrides));
}

function makeStaffCredentialingDocument(string $staffProfileId, ?string $tenantId = null, ?int $uploadedByUserId = null): StaffDocumentModel
{
    return StaffDocumentModel::query()->create([
        'staff_profile_id' => $staffProfileId,
        'tenant_id' => $tenantId,
        'document_type' => 'license_copy',
        'title' => 'License Copy',
        'description' => 'Verification source document',
        'file_path' => 'staff-documents/test/license.pdf',
        'original_filename' => 'license.pdf',
        'mime_type' => 'application/pdf',
        'file_size_bytes' => 1200,
        'checksum_sha256' => str_repeat('a', 64),
        'issued_at' => '2026-01-01',
        'expires_at' => '2026-12-31',
        'verification_status' => 'verified',
        'verification_reason' => null,
        'status' => 'active',
        'status_reason' => null,
        'uploaded_by_user_id' => $uploadedByUserId,
        'verified_by_user_id' => $uploadedByUserId,
        'verified_at' => now(),
    ]);
}

it('requires authentication for staff credentialing endpoints', function (): void {
    $profile = makeStaffCredentialingProfile();

    $this->getJson('/api/v1/staff/'.$profile->id.'/credentialing/summary')
        ->assertUnauthorized();

    $this->postJson('/api/v1/staff/'.$profile->id.'/credentialing/regulatory-profile', staffRegulatoryProfilePayload())
        ->assertUnauthorized();

    $this->getJson('/api/v1/staff/credentialing-alerts')
        ->assertUnauthorized();
});

it('batches credentialing summaries for visible staff cards', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.read',
    ]);
    $readyProfile = makeStaffCredentialingProfile();
    $blockedProfile = makeStaffCredentialingProfile();

    $readyRegulatoryProfile = makeStaffCredentialingRegulatoryProfile($readyProfile->id, $readyProfile->tenant_id);
    makeStaffCredentialingRegistration(
        staffProfileId: $readyProfile->id,
        tenantId: $readyProfile->tenant_id,
        staffRegulatoryProfileId: $readyRegulatoryProfile->id,
        overrides: [
            'verification_status' => 'verified',
            'expires_at' => now()->addMonths(6)->toDateString(),
        ],
    );

    $response = $this->actingAs($actor)
        ->getJson('/api/v1/staff/credentialing/summaries?ids='.$readyProfile->id.','.$blockedProfile->id)
        ->assertOk()
        ->json('data');

    expect($response)->toHaveCount(2);
    expect($response[0]['id'])->toBe($readyProfile->id);
    expect($response[0]['credentialingState'])->toBe('ready');
    expect($response[1]['id'])->toBe($blockedProfile->id);
    expect($response[1]['credentialingState'])->toBe('blocked');
    expect($response[1]['blockingReasons'])->toContain('No regulatory profile is recorded.');
});

it('returns not required summary for non-clinical staff without credentialing records', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.read',
    ]);
    $targetUser = User::factory()->create();

    $profile = StaffProfileModel::query()->create([
        'user_id' => $targetUser->id,
        'employee_number' => 'STF-CRD-NONCLINICAL',
        'department' => 'Human Resources',
        'job_title' => 'Staff Administrator',
        'professional_license_number' => null,
        'license_type' => null,
        'phone_extension' => null,
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$profile->id.'/credentialing/summary')
        ->assertOk()
        ->assertJsonPath('data.credentialingState', 'not_required')
        ->assertJsonPath('data.blockingReasons.0', 'Credentialing is not required for this non-clinical staff role.');
});

it('treats clinical roles as credentialing-required even when license type includes registration wording', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.read',
    ]);
    $targetUser = User::factory()->create();

    $profile = StaffProfileModel::query()->create([
        'user_id' => $targetUser->id,
        'employee_number' => 'STF-CRD-CLINICAL-REG',
        'department' => 'General OPD',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'LIC-CRD-CLINICAL-001',
        'license_type' => 'Full Registration',
        'phone_extension' => null,
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$profile->id.'/credentialing/summary')
        ->assertOk()
        ->assertJsonPath('data.credentialingState', 'blocked')
        ->assertJsonPath('data.blockingReasons.0', 'No regulatory profile is recorded.');
});

it('returns an empty regulatory profile payload before the profile is created', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.read',
    ]);
    $profile = makeStaffCredentialingProfile();

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$profile->id.'/credentialing/regulatory-profile')
        ->assertOk()
        ->assertJsonPath('data', null);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.Str::uuid()->toString().'/credentialing/regulatory-profile')
        ->assertNotFound();
});

it('creates and updates regulatory profiles and writes audit logs', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.read',
        'staff.credentialing.manage-profile',
        'staff.credentialing.view-audit-logs',
    ]);
    $context = makeStaffCredentialingContext('TCRDPROF', 'TCRD-PROF');
    $profile = makeStaffCredentialingProfile($context['tenant']->id);

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/credentialing/regulatory-profile', staffRegulatoryProfilePayload())
        ->assertCreated()
        ->assertJsonPath('data.staffProfileId', $profile->id)
        ->assertJsonPath('data.primaryRegulatorCode', 'mct')
        ->assertJsonPath('data.cadreCode', 'medical_doctor')
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/credentialing/regulatory-profile', [
            'goodStandingStatus' => 'pending',
            'notes' => 'Pending manual council verification.',
        ])
        ->assertOk()
        ->assertJsonPath('data.goodStandingStatus', 'pending')
        ->assertJsonPath('data.notes', 'Pending manual council verification.');

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$profile->id.'/credentialing/audit-logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    expect(
        StaffCredentialingAuditLogModel::query()
            ->where('staff_profile_id', $profile->id)
            ->where('staff_regulatory_profile_id', $created['id'])
            ->where('action', 'staff-credentialing.regulatory-profile.updated')
            ->exists()
    )->toBeTrue();
});

it('rejects duplicate regulatory profiles for the same staff member', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.manage-profile',
    ]);
    $profile = makeStaffCredentialingProfile();

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/credentialing/regulatory-profile', staffRegulatoryProfilePayload())
        ->assertCreated();

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/credentialing/regulatory-profile', staffRegulatoryProfilePayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payload']);
});

it('blocks sensitive credentialing writes when the linked user email is unverified', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.manage-profile',
    ]);
    $targetUser = User::factory()->unverified()->create();
    $profile = StaffProfileModel::query()->create([
        'user_id' => $targetUser->id,
        'employee_number' => 'STF-CRD-UNVERIFIED',
        'department' => 'Medical',
        'job_title' => 'Medical Officer',
        'professional_license_number' => 'LIC-CRD-UNVERIFIED',
        'license_type' => 'Medical Officer',
        'phone_extension' => '210',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/credentialing/regulatory-profile', staffRegulatoryProfilePayload())
        ->assertStatus(422)
        ->assertJsonPath('errors.linkedUser.0', 'Linked user email '.$targetUser->email.' is not verified. Sensitive credentialing and privileging actions stay blocked until the user completes the invite or verification flow.');
});

it('creates, lists, and rejects duplicate professional registrations', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.read',
        'staff.credentialing.manage-registrations',
    ]);
    $profile = makeStaffCredentialingProfile();
    $document = makeStaffCredentialingDocument($profile->id, $profile->tenant_id, $actor->id);

    $payload = staffProfessionalRegistrationPayload($document->id, [
        'registrationNumber' => 'MCT-REG-001',
    ]);

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/credentialing/registrations', $payload)
        ->assertCreated()
        ->assertJsonPath('data.staffProfileId', $profile->id)
        ->assertJsonPath('data.registrationNumber', 'MCT-REG-001')
        ->assertJsonPath('data.sourceDocumentId', $document->id)
        ->assertJsonPath('data.verificationStatus', 'pending')
        ->json('data');

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$profile->id.'/credentialing/registrations')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $created['id']);

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/credentialing/registrations', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['registrationNumber']);
});

it('rejects source document assignments from a different staff member', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.manage-registrations',
    ]);
    $profile = makeStaffCredentialingProfile();
    $otherProfile = makeStaffCredentialingProfile();
    $foreignDocument = makeStaffCredentialingDocument($otherProfile->id, $otherProfile->tenant_id, $actor->id);

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/credentialing/registrations', staffProfessionalRegistrationPayload($foreignDocument->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sourceDocumentId']);
});

it('requires a reason when rejecting registration verification and records transition metadata', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.verify',
    ]);
    $profile = makeStaffCredentialingProfile();
    $regulatoryProfile = makeStaffCredentialingRegulatoryProfile($profile->id, $profile->tenant_id);
    $registration = makeStaffCredentialingRegistration(
        staffProfileId: $profile->id,
        tenantId: $profile->tenant_id,
        staffRegulatoryProfileId: $regulatoryProfile->id,
        overrides: [
            'verification_status' => 'pending',
            'verified_at' => null,
        ],
    );

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/credentialing/registrations/'.$registration->id.'/verification', [
            'verificationStatus' => 'rejected',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/credentialing/registrations/'.$registration->id.'/verification', [
            'verificationStatus' => 'rejected',
            'reason' => 'Primary source verification mismatch',
            'verificationNotes' => 'Council number could not be matched.',
        ])
        ->assertOk()
        ->assertJsonPath('data.verificationStatus', 'rejected')
        ->assertJsonPath('data.verificationReason', 'Primary source verification mismatch')
        ->assertJsonPath('data.verificationNotes', 'Council number could not be matched.');

    $log = StaffCredentialingAuditLogModel::query()
        ->where('staff_profile_id', $profile->id)
        ->where('staff_professional_registration_id', $registration->id)
        ->where('action', 'staff-credentialing.registration.verification.updated')
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull();
    expect($log?->metadata['transition']['from'] ?? null)->toBe('pending');
    expect($log?->metadata['transition']['to'] ?? null)->toBe('rejected');
    expect($log?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($log?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('returns blocked credentialing summary when only expired active records exist', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.read',
    ]);
    $profile = makeStaffCredentialingProfile();
    $regulatoryProfile = makeStaffCredentialingRegulatoryProfile($profile->id, $profile->tenant_id);

    makeStaffCredentialingRegistration(
        staffProfileId: $profile->id,
        tenantId: $profile->tenant_id,
        staffRegulatoryProfileId: $regulatoryProfile->id,
        overrides: [
            'verification_status' => 'verified',
            'expires_at' => '2025-12-31',
        ],
    );

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$profile->id.'/credentialing/summary')
        ->assertOk()
        ->assertJsonPath('data.credentialingState', 'blocked')
        ->assertJsonPath('data.registrationSummary.expired', 1);
});

it('returns pending verification summary when active-looking registration is unverified', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.read',
    ]);
    $profile = makeStaffCredentialingProfile();
    $regulatoryProfile = makeStaffCredentialingRegulatoryProfile($profile->id, $profile->tenant_id);

    makeStaffCredentialingRegistration(
        staffProfileId: $profile->id,
        tenantId: $profile->tenant_id,
        staffRegulatoryProfileId: $regulatoryProfile->id,
        overrides: [
            'verification_status' => 'pending',
            'verified_at' => null,
            'verified_by_user_id' => null,
            'expires_at' => '2026-12-31',
        ],
    );

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$profile->id.'/credentialing/summary')
        ->assertOk()
        ->assertJsonPath('data.credentialingState', 'pending_verification')
        ->assertJsonPath('data.registrationSummary.pendingVerification', 1);
});

it('filters credentialing alerts by state and alert type', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.read',
    ]);

    $missingProfile = makeStaffCredentialingProfile();
    $watchProfile = makeStaffCredentialingProfile();
    $watchRegulatoryProfile = makeStaffCredentialingRegulatoryProfile($watchProfile->id, $watchProfile->tenant_id);

    makeStaffCredentialingRegistration(
        staffProfileId: $watchProfile->id,
        tenantId: $watchProfile->tenant_id,
        staffRegulatoryProfileId: $watchRegulatoryProfile->id,
        overrides: [
            'expires_at' => now()->addDays(10)->toDateString(),
        ],
    );

    $missingProfileUser = User::query()->findOrFail($missingProfile->user_id);
    $watchProfileUser = User::query()->findOrFail($watchProfile->user_id);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/credentialing-alerts?alertType=missing_regulatory_profile')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.staffProfileId', $missingProfile->id)
        ->assertJsonPath('data.0.userName', $missingProfileUser->name)
        ->assertJsonPath('data.0.alertType', 'missing_regulatory_profile');

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/credentialing-alerts?alertState=watch')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.staffProfileId', $watchProfile->id)
        ->assertJsonPath('data.0.userName', $watchProfileUser->name)
        ->assertJsonPath('data.0.alertState', 'watch');
});

it('lists credentialing audit logs across profile and registration events', function (): void {
    $actor = makeStaffCredentialingActor([
        'staff.credentialing.read',
        'staff.credentialing.manage-profile',
        'staff.credentialing.manage-registrations',
        'staff.credentialing.verify',
        'staff.credentialing.view-audit-logs',
    ]);
    $profile = makeStaffCredentialingProfile();

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/credentialing/regulatory-profile', staffRegulatoryProfilePayload())
        ->assertCreated();

    $registration = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/credentialing/registrations', staffProfessionalRegistrationPayload())
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/credentialing/registrations/'.$registration['id'], [
            'notes' => 'Updated after HR review.',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/credentialing/registrations/'.$registration['id'].'/verification', [
            'verificationStatus' => 'verified',
            'verificationNotes' => 'Verified against regulator record.',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$profile->id.'/credentialing/audit-logs?perPage=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.perPage', 2);
});

it('blocks credentialing writes when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $actor = makeStaffCredentialingActor([
        'staff.credentialing.manage-profile',
        'staff.credentialing.manage-registrations',
    ]);
    $context = makeStaffCredentialingContext('TCRDGRD', 'TCRD-GRD');
    $profile = makeStaffCredentialingProfile($context['tenant']->id);

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/credentialing/regulatory-profile', staffRegulatoryProfilePayload())
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});
