<?php

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use App\Modules\Staff\Infrastructure\Models\ClinicalPrivilegeCatalogModel;
use App\Modules\Staff\Infrastructure\Models\ClinicalSpecialtyModel;
use App\Modules\Staff\Infrastructure\Models\StaffDocumentModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfessionalRegistrationModel;
use App\Modules\Staff\Infrastructure\Models\StaffPrivilegeGrantAuditLogModel;
use App\Modules\Staff\Infrastructure\Models\StaffPrivilegeGrantModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Modules\Staff\Infrastructure\Models\StaffRegulatoryProfileModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeStaffPrivilegeActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function makeStaffPrivilegeProfile(?string $tenantId = null): StaffProfileModel
{
    $targetUser = User::factory()->create();

    return StaffProfileModel::query()->create([
        'tenant_id' => $tenantId,
        'user_id' => $targetUser->id,
        'employee_number' => 'STF-PRV-'.strtoupper(Str::random(10)),
        'department' => 'Outpatient',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'LIC-PRV-'.strtoupper(Str::random(6)),
        'license_type' => 'Clinical Officer',
        'phone_extension' => '205',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);
}

function makeStaffPrivilegeSpecialty(?string $tenantId = null, ?string $code = null): ClinicalSpecialtyModel
{
    return ClinicalSpecialtyModel::query()->create([
        'tenant_id' => $tenantId,
        'code' => $code ?? 'SP-PRV-'.strtoupper(Str::random(6)),
        'name' => 'Privilege Specialty '.strtoupper(Str::random(4)),
        'description' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);
}

function makeStaffPrivilegeCatalog(
    string $specialtyId,
    ?string $tenantId = null,
    ?string $code = null,
    string $status = 'active',
    ?string $cadreCode = 'medical_doctor',
    ?string $facilityType = 'hospital',
): ClinicalPrivilegeCatalogModel {
    return ClinicalPrivilegeCatalogModel::query()->create([
        'tenant_id' => $tenantId,
        'specialty_id' => $specialtyId,
        'code' => $code ?? 'CAT-PRV-'.strtoupper(Str::random(6)),
        'name' => 'Catalog Privilege '.strtoupper(Str::random(4)),
        'description' => 'Catalog-backed privilege template for API coverage.',
        'cadre_code' => $cadreCode,
        'facility_type' => $facilityType,
        'status' => $status,
        'status_reason' => $status === 'active' ? null : 'Disabled for test coverage.',
    ]);
}

function makeStaffPrivilegeCredentialingReady(StaffProfileModel $profile, string $cadreCode = 'medical_doctor'): void
{
    $regulatoryProfile = StaffRegulatoryProfileModel::query()->updateOrCreate(
        ['staff_profile_id' => $profile->id],
        [
            'tenant_id' => $profile->tenant_id,
            'primary_regulator_code' => 'mct',
            'cadre_code' => $cadreCode,
            'professional_title' => 'Medical Doctor',
            'registration_type' => 'full',
            'practice_authority_level' => 'independent',
            'supervision_level' => 'independent',
            'good_standing_status' => 'in_good_standing',
            'good_standing_checked_at' => '2026-03-01',
            'notes' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ],
    );

    StaffProfessionalRegistrationModel::query()->updateOrCreate(
        [
            'staff_profile_id' => $profile->id,
            'regulator_code' => 'mct',
            'registration_number' => 'REG-PRV-'.$profile->id,
        ],
        [
            'tenant_id' => $profile->tenant_id,
            'staff_regulatory_profile_id' => $regulatoryProfile->id,
            'registration_category' => 'annual_practicing_license',
            'license_number' => 'LIC-PRV-'.substr(strtoupper(str_replace('-', '', $profile->id)), 0, 12),
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
            'cpd_points_earned' => 18,
            'source_document_id' => null,
            'source_system' => 'manual_entry',
            'notes' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ],
    );
}

function staffPrivilegePayload(string $facilityId, string $specialtyId, array $overrides = []): array
{
    return array_merge([
        'facilityId' => $facilityId,
        'specialtyId' => $specialtyId,
        'privilegeCode' => 'PRV-OPD-01',
        'privilegeName' => 'Outpatient Assessment',
        'scopeNotes' => 'Can conduct OPD triage and review.',
        'grantedAt' => '2026-03-01',
        'reviewDueAt' => '2026-09-01',
    ], $overrides);
}

/**
 * @return array{tenant: TenantModel, facility: FacilityModel}
 */
function makeStaffPrivilegeContext(string $tenantCode, string $facilityCode): array
{
    $tenant = TenantModel::query()->create([
        'code' => strtoupper($tenantCode),
        'name' => 'Staff Privilege Tenant '.strtoupper($tenantCode),
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => strtoupper($facilityCode),
        'name' => 'Staff Privilege Facility '.strtoupper($facilityCode),
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    return [
        'tenant' => $tenant,
        'facility' => $facility,
    ];
}

function makeStaffPrivilegeFacility(string $tenantId, string $code, string $facilityType = 'hospital'): FacilityModel
{
    return FacilityModel::query()->create([
        'tenant_id' => $tenantId,
        'code' => strtoupper($code),
        'name' => 'Staff Privilege Facility '.strtoupper($code),
        'facility_type' => $facilityType,
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);
}

function transitionStaffPrivilegeGrantToActive(object $test, User $actor, StaffProfileModel $profile, string $grantId): void
{
    foreach ([
        'staff.privileges.review',
        'staff.privileges.approve',
        'staff.privileges.update-status',
    ] as $permission) {
        if (! $actor->hasPermissionTo($permission)) {
            $actor->givePermissionTo($permission);
        }
    }

    $test->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$grantId.'/status', [
            'status' => 'under_review',
            'reason' => 'Submitted to department review',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'under_review');

    $test->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$grantId.'/status', [
            'status' => 'approved',
            'reason' => 'Approved by privileging committee',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $test->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$grantId.'/status', [
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
}

/**
 * @return array{0:string,1:string}
 */
function seedStaffPrivilegeScopeAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedStaffPrivilegeScopeFacility(
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
function seedStaffPrivilegeScopeFacility(
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

it('requires authentication for staff privilege list and creation', function (): void {
    $context = makeStaffPrivilegeContext('TPRVAUTH', 'TPRV-AUTH');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id);

    $this->getJson('/api/v1/staff/'.$profile->id.'/privileges')
        ->assertUnauthorized();

    $this->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
        facilityId: $context['facility']->id,
        specialtyId: $specialty->id,
    ))->assertUnauthorized();
});

it('loads an aggregated privilege coverage board for the current staff scope', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.read',
        'staff.privileges.read',
        'staff.privileges.create',
        'staff.privileges.review',
        'staff.privileges.approve',
        'staff.privileges.update-status',
        'staff.documents.read',
        'staff.credentialing.read',
    ]);
    $context = makeStaffPrivilegeContext('TPRVBRD', 'TPRV-BRD');
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-BRD');

    $profiles = [];
    for ($index = 0; $index < 13; $index++) {
        $profiles[] = makeStaffPrivilegeProfile($context['tenant']->id);
    }

    $readyProfile = $profiles[0];
    $blockedProfile = $profiles[1];

    makeStaffPrivilegeCredentialingReady($readyProfile);
    makeStaffPrivilegeCredentialingReady($blockedProfile);

    $readyGrant = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$readyProfile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
            overrides: [
                'privilegeCode' => 'PRV-BRD-READY',
                'privilegeName' => 'Board Ready Privilege',
            ],
        ))
        ->assertCreated()
        ->json('data');
    transitionStaffPrivilegeGrantToActive($this, $actor, $readyProfile, $readyGrant['id']);

    $blockedGrant = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$blockedProfile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
            overrides: [
                'privilegeCode' => 'PRV-BRD-BLOCKED',
                'privilegeName' => 'Board Blocked Privilege',
            ],
        ))
        ->assertCreated()
        ->json('data');
    transitionStaffPrivilegeGrantToActive($this, $actor, $blockedProfile, $blockedGrant['id']);

    StaffProfessionalRegistrationModel::query()
        ->where('staff_profile_id', $blockedProfile->id)
        ->update([
            'registration_status' => 'expired',
            'license_status' => 'expired',
            'expires_at' => now()->subDay()->toDateString(),
        ]);

    StaffDocumentModel::query()->create([
        'staff_profile_id' => $readyProfile->id,
        'tenant_id' => $context['tenant']->id,
        'document_type' => 'practicing_license',
        'title' => 'Current Practicing License',
        'description' => null,
        'file_path' => 'staff-documents/test/practicing-license.pdf',
        'original_filename' => 'practicing-license.pdf',
        'mime_type' => 'application/pdf',
        'file_size_bytes' => 2048,
        'checksum_sha256' => str_repeat('b', 64),
        'issued_at' => '2026-01-01',
        'expires_at' => now()->addDays(5)->toDateString(),
        'verification_status' => 'verified',
        'verification_reason' => null,
        'status' => 'active',
        'status_reason' => null,
        'uploaded_by_user_id' => $actor->id,
        'verified_by_user_id' => $actor->id,
        'verified_at' => now(),
    ]);

    $response = $this->actingAs($actor)
        ->getJson('/api/v1/staff/privileges/coverage-board?status=active')
        ->assertOk()
        ->assertJsonPath('meta.totalMatchingStaff', 13)
        ->assertJsonCount(13, 'data')
        ->json();

    $rows = collect($response['data']);
    $readyRow = $rows->firstWhere('id', $readyProfile->id);
    $blockedRow = $rows->firstWhere('id', $blockedProfile->id);

    expect($readyRow)->not()->toBeNull();
    expect($readyRow['privileges'])->toHaveCount(1);
    expect($readyRow['documents'])->toHaveCount(1);
    expect($readyRow['credentialingSummary']['credentialingState'])->toBe('ready');

    expect($blockedRow)->not()->toBeNull();
    expect($blockedRow['privileges'])->toHaveCount(1);
    expect($blockedRow['credentialingSummary']['credentialingState'])->toBe('blocked');
});

it('creates and fetches staff privilege grants when authorized', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.read',
        'staff.privileges.create',
    ]);
    $context = makeStaffPrivilegeContext('TPRVCRT', 'TPRV-CRT');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-CRT');

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertCreated()
        ->assertJsonPath('data.staffProfileId', $profile->id)
        ->assertJsonPath('data.facilityId', $context['facility']->id)
        ->assertJsonPath('data.specialtyId', $specialty->id)
        ->assertJsonPath('data.privilegeCode', 'PRV-OPD-01')
        ->assertJsonPath('data.status', 'requested')
        ->json('data');

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.id', $created['id']);
});

it('lists privilege catalog templates when authorized', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.read',
    ]);
    $context = makeStaffPrivilegeContext('TPRVCAT', 'TPRV-CAT');
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-CAT');
    $activeCatalog = makeStaffPrivilegeCatalog(
        specialtyId: $specialty->id,
        tenantId: $context['tenant']->id,
        code: 'PRV-CAT-001',
    );
    makeStaffPrivilegeCatalog(
        specialtyId: $specialty->id,
        tenantId: $context['tenant']->id,
        code: 'PRV-CAT-002',
        status: 'inactive',
    );

    $this->actingAs($actor)
        ->getJson('/api/v1/privilege-catalogs?status=active&sortBy=code&sortDir=asc')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $activeCatalog->id)
        ->assertJsonPath('data.0.specialtyId', $specialty->id)
        ->assertJsonPath('data.0.code', 'PRV-CAT-001');
});

it('creates staff privilege grants from catalog templates when authorized', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
    ]);
    $context = makeStaffPrivilegeContext('TPRVCFG', 'TPRV-CFG');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-CFG');
    $catalog = makeStaffPrivilegeCatalog(
        specialtyId: $specialty->id,
        tenantId: $context['tenant']->id,
        code: 'PRV-CAT-OPD',
    );

    $response = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', [
            'facilityId' => $context['facility']->id,
            'privilegeCatalogId' => $catalog->id,
            'scopeNotes' => 'Granted from governed catalog template.',
            'grantedAt' => '2026-03-01',
            'reviewDueAt' => '2026-09-01',
        ])
        ->assertCreated()
        ->assertJsonPath('data.facilityId', $context['facility']->id)
        ->assertJsonPath('data.specialtyId', $specialty->id)
        ->assertJsonPath('data.privilegeCatalogId', $catalog->id)
        ->assertJsonPath('data.privilegeCode', 'PRV-CAT-OPD')
        ->assertJsonPath('data.privilegeName', $catalog->name);

    $grantId = (string) $response->json('data.id');
    $grant = StaffPrivilegeGrantModel::query()->findOrFail($grantId);

    expect($grant->privilege_catalog_id)->toBe($catalog->id);
    expect($grant->specialty_id)->toBe($specialty->id);
    expect($grant->privilege_code)->toBe('PRV-CAT-OPD');
    expect($grant->status)->toBe('requested');
});

it('rejects catalog grants when staff cadre does not match template restriction', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
    ]);
    $context = makeStaffPrivilegeContext('TPRVCAD', 'TPRV-CAD');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile, 'clinical_officer');
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-CAD');
    $catalog = makeStaffPrivilegeCatalog(
        specialtyId: $specialty->id,
        tenantId: $context['tenant']->id,
        code: 'PRV-CAT-CAD',
        cadreCode: 'medical_doctor',
        facilityType: 'hospital',
    );

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', [
            'facilityId' => $context['facility']->id,
            'privilegeCatalogId' => $catalog->id,
            'scopeNotes' => 'Trying to grant a template outside cadre scope.',
            'grantedAt' => '2026-03-01',
            'reviewDueAt' => '2026-09-01',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['privilegeCatalogId']);
});

it('rejects catalog grants when facility type does not match template restriction', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
    ]);
    $context = makeStaffPrivilegeContext('TPRVFAC', 'TPRV-FAC');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $dispensary = makeStaffPrivilegeFacility($context['tenant']->id, 'TPRV-FAC-DSP', 'dispensary');
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-FAC');
    $catalog = makeStaffPrivilegeCatalog(
        specialtyId: $specialty->id,
        tenantId: $context['tenant']->id,
        code: 'PRV-CAT-FAC',
        cadreCode: 'medical_doctor',
        facilityType: 'hospital',
    );

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', [
            'facilityId' => $dispensary->id,
            'privilegeCatalogId' => $catalog->id,
            'scopeNotes' => 'Trying to grant a hospital-only template at a dispensary.',
            'grantedAt' => '2026-03-01',
            'reviewDueAt' => '2026-09-01',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['privilegeCatalogId']);
});

it('forbids staff privilege list without read permission', function (): void {
    $actor = makeStaffPrivilegeActor();
    $context = makeStaffPrivilegeContext('TPRVFORB', 'TPRV-FORB');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$profile->id.'/privileges')
        ->assertForbidden();
});

it('updates staff privilege grant metadata and writes audit logs', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
        'staff.privileges.update',
    ]);
    $context = makeStaffPrivilegeContext('TPRVUPD', 'TPRV-UPD');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-UPD');

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertCreated()
        ->json('data');

    $response = $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'], [
            'privilegeName' => 'Updated OPD Assessment',
            'scopeNotes' => 'Expanded role for post-op OPD review.',
            'reviewDueAt' => '2026-10-01',
        ])
        ->assertOk()
        ->assertJsonPath('data.privilegeName', 'Updated OPD Assessment')
        ->assertJsonPath('data.scopeNotes', 'Expanded role for post-op OPD review.');

    expect((string) $response->json('data.reviewDueAt'))->toStartWith('2026-10-01');

    expect(
        StaffPrivilegeGrantAuditLogModel::query()
            ->where('staff_privilege_grant_id', $created['id'])
            ->where('action', 'staff-privilege-grant.updated')
            ->exists()
    )->toBeTrue();
});

it('moves privilege requests through review, approval, and activation workflow states', function (): void {
    $creator = makeStaffPrivilegeActor([
        'staff.privileges.create',
    ]);
    $reviewer = makeStaffPrivilegeActor([
        'staff.privileges.review',
    ]);
    $approver = makeStaffPrivilegeActor([
        'staff.privileges.approve',
    ]);
    $activator = makeStaffPrivilegeActor([
        'staff.privileges.update-status',
    ]);
    $context = makeStaffPrivilegeContext('TPRVWFL', 'TPRV-WFL');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-WFL');

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertCreated()
        ->assertJsonPath('data.status', 'requested')
        ->json('data');

    $this->actingAs($reviewer)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
            'status' => 'under_review',
            'reason' => 'Sent to credentials committee',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'under_review')
        ->assertJsonPath('data.reviewerUserId', $reviewer->id)
        ->assertJsonPath('data.reviewNote', 'Sent to credentials committee')
        ->assertJsonPath('data.reviewStartedAt', fn (mixed $value): bool => is_string($value) && $value !== '');

    $this->actingAs($approver)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
            'status' => 'approved',
            'reason' => 'Committee approved the request',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approverUserId', $approver->id)
        ->assertJsonPath('data.approvalNote', 'Committee approved the request')
        ->assertJsonPath('data.approvedAt', fn (mixed $value): bool => is_string($value) && $value !== '');

    $this->actingAs($activator)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.activatedAt', fn (mixed $value): bool => is_string($value) && $value !== '');
});

it('requires role-specific workflow permissions for review and approval stages', function (): void {
    $creator = makeStaffPrivilegeActor([
        'staff.privileges.create',
    ]);
    $reviewer = makeStaffPrivilegeActor([
        'staff.privileges.review',
    ]);
    $nonApprover = makeStaffPrivilegeActor([
        'staff.privileges.update-status',
    ]);
    $approver = makeStaffPrivilegeActor([
        'staff.privileges.approve',
    ]);
    $context = makeStaffPrivilegeContext('TPRVPERM', 'TPRV-PERM');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-PERM');

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertCreated()
        ->json('data');

    $this->actingAs($nonApprover)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
            'status' => 'under_review',
            'reason' => 'Attempted without review permission',
        ])
        ->assertForbidden();

    $this->actingAs($reviewer)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
            'status' => 'under_review',
            'reason' => 'Reviewer accepted the request for committee agenda.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'under_review');

    $this->actingAs($reviewer)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
            'status' => 'approved',
            'reason' => 'Reviewer tried to approve without committee authority.',
        ])
        ->assertForbidden();

    $this->actingAs($approver)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
            'status' => 'approved',
            'reason' => 'Medical staff committee approved the request.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approverUserId', $approver->id);
});

it('rejects invalid workflow jumps for privilege status transitions', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
        'staff.privileges.update-status',
    ]);
    $context = makeStaffPrivilegeContext('TPRVJMP', 'TPRV-JMP');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-JMP');

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('rejects catalog-backed facility updates when the next facility falls outside template scope', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
        'staff.privileges.update',
    ]);
    $context = makeStaffPrivilegeContext('TPRVFUP', 'TPRV-FUP');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-FUP');
    $catalog = makeStaffPrivilegeCatalog(
        specialtyId: $specialty->id,
        tenantId: $context['tenant']->id,
        code: 'PRV-CAT-FUP',
        facilityType: 'hospital',
    );
    $dispensary = makeStaffPrivilegeFacility($context['tenant']->id, 'TPRV-FUP-DSP', 'dispensary');

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', [
            'facilityId' => $context['facility']->id,
            'privilegeCatalogId' => $catalog->id,
            'scopeNotes' => 'Granted from governed catalog template.',
            'grantedAt' => '2026-03-01',
            'reviewDueAt' => '2026-09-01',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'], [
            'facilityId' => $dispensary->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['privilegeCatalogId']);
});

it('rejects status lifecycle fields on privilege detail update endpoint', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
        'staff.privileges.update',
    ]);
    $context = makeStaffPrivilegeContext('TPRVUPDGRD', 'TPRV-UPD-GRD');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-UPD-GRD');

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'], [
            'privilegeName' => 'Should Not Persist',
            'status' => 'retired',
            'reason' => 'Role changed',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status', 'reason']);

    $grant = StaffPrivilegeGrantModel::query()->findOrFail($created['id']);
    expect($grant->privilege_name)->toBe('Outpatient Assessment');
    expect($grant->status)->toBe('requested');
});

it('enforces reason when suspending or retiring privilege grants', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
        'staff.privileges.update-status',
    ]);
    $context = makeStaffPrivilegeContext('TPRVSTS', 'TPRV-STS');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-STS');

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertCreated()
        ->json('data');

    transitionStaffPrivilegeGrantToActive($this, $actor, $profile, $created['id']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
            'status' => 'suspended',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
            'status' => 'retired',
            'reason' => 'Clinical role changed',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'retired')
        ->assertJsonPath('data.statusReason', 'Clinical role changed');

    $statusLog = StaffPrivilegeGrantAuditLogModel::query()
        ->where('staff_privilege_grant_id', $created['id'])
        ->where('action', 'staff-privilege-grant.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('active');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('retired');
    expect($statusLog?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('lists and exports staff privilege grant audit logs when authorized', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
        'staff.privileges.update',
        'staff.privileges.review',
        'staff.privileges.update-status',
        'staff.privileges.view-audit-logs',
    ]);
    $context = makeStaffPrivilegeContext('TPRVAUD', 'TPRV-AUD');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-AUD');

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'], [
        'scopeNotes' => 'Updated scope for audit log validation.',
    ])->assertOk();

    $this->actingAs($actor)->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
        'status' => 'under_review',
        'reason' => 'Pending committee review',
    ])->assertOk();

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/audit-logs?perPage=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.perPage', 2);

    $response = $this->actingAs($actor)
        ->get('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/audit-logs/export?action=staff-privilege-grant.updated')
        ->assertOk()
        ->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');

    $csv = $response->streamedContent();
    expect($csv)->toContain('staff-privilege-grant.updated');
    expect($csv)->not->toContain('staff-privilege-grant.status.updated');
});

it('rejects duplicate staff privilege code within the same scope', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
    ]);
    $context = makeStaffPrivilegeContext('TPRVDUP', 'TPRV-DUP');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-DUP');

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertCreated();

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
            overrides: ['privilegeName' => 'Duplicate privilege'],
        ))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['privilegeCode']);
});

it('blocks privilege grant creation when credentialing is not ready', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
    ]);
    $context = makeStaffPrivilegeContext('TPRVBLK', 'TPRV-BLK');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-BLK');

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['staffProfileId'])
        ->assertJsonPath('message', 'Privilege requests cannot be submitted until staff credentialing is ready. No regulatory profile is recorded.');

    expect(
        StaffPrivilegeGrantModel::query()
            ->where('staff_profile_id', $profile->id)
            ->exists()
    )->toBeFalse();
});

it('blocks privilege grant creation when the linked user email is unverified', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
    ]);
    $context = makeStaffPrivilegeContext('TPRVMAIL', 'TPRV-MAIL');
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-MAIL');
    $targetUser = User::factory()->unverified()->create();
    $profile = StaffProfileModel::query()->create([
        'tenant_id' => $context['tenant']->id,
        'user_id' => $targetUser->id,
        'employee_number' => 'STF-PRV-UNVERIFIED',
        'department' => 'Outpatient',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'LIC-PRV-UNVERIFIED',
        'license_type' => 'Clinical Officer',
        'phone_extension' => '205',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);
    makeStaffPrivilegeCredentialingReady($profile);

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertStatus(422)
        ->assertJsonPath('errors.linkedUser.0', 'Linked user email '.$targetUser->email.' is not verified. Sensitive credentialing and privileging actions stay blocked until the user completes the invite or verification flow.');
});

it('blocks privilege reactivation when credentialing is not ready', function (): void {
    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
        'staff.privileges.update-status',
    ]);
    $context = makeStaffPrivilegeContext('TPRVREA', 'TPRV-REA');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    makeStaffPrivilegeCredentialingReady($profile);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-REA');

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertCreated()
        ->json('data');

    transitionStaffPrivilegeGrantToActive($this, $actor, $profile, $created['id']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
            'status' => 'suspended',
            'reason' => 'Temporary hold',
        ])
        ->assertOk();

    StaffProfessionalRegistrationModel::query()
        ->where('staff_profile_id', $profile->id)
        ->update([
            'license_status' => 'expired',
            'expires_at' => '2026-02-01',
        ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$created['id'].'/status', [
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status'])
        ->assertJsonPath('message', 'Privilege grants cannot be activated until staff credentialing is ready. No active verified registration or license is available.');

    expect(
        StaffPrivilegeGrantModel::query()->findOrFail($created['id'])->status
    )->toBe('suspended');
});

it('filters staff privilege reads by tenant scope when platform multi tenant isolation is enabled', function (): void {
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $actor = makeStaffPrivilegeActor(['staff.privileges.read']);
    [$tenantId, $facilityId] = seedStaffPrivilegeScopeAssignment(
        userId: $actor->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-PRV',
        facilityName: 'Nairobi Privileging',
    );
    [$otherTenantId, $otherFacilityId] = seedStaffPrivilegeScopeFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-PRV',
        facilityName: 'Dar Privileging',
    );

    $visibleProfile = makeStaffPrivilegeProfile($tenantId);
    $hiddenProfile = makeStaffPrivilegeProfile($otherTenantId);
    $visibleSpecialty = makeStaffPrivilegeSpecialty($tenantId, 'SP-PRV-VIS');
    $hiddenSpecialty = makeStaffPrivilegeSpecialty($otherTenantId, 'SP-PRV-HID');

    $visibleGrant = StaffPrivilegeGrantModel::query()->create([
        'staff_profile_id' => $visibleProfile->id,
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'specialty_id' => $visibleSpecialty->id,
        'privilege_code' => 'PRV-VIS-001',
        'privilege_name' => 'Visible Privilege',
        'scope_notes' => null,
        'granted_at' => '2026-03-01',
        'review_due_at' => '2026-09-01',
        'status' => 'active',
        'status_reason' => null,
        'granted_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
    ]);

    StaffPrivilegeGrantModel::query()->create([
        'staff_profile_id' => $hiddenProfile->id,
        'tenant_id' => $otherTenantId,
        'facility_id' => $otherFacilityId,
        'specialty_id' => $hiddenSpecialty->id,
        'privilege_code' => 'PRV-HID-001',
        'privilege_name' => 'Hidden Privilege',
        'scope_notes' => null,
        'granted_at' => '2026-03-01',
        'review_due_at' => '2026-09-01',
        'status' => 'active',
        'status_reason' => null,
        'granted_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
    ]);

    $this->actingAs($actor)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-PRV',
        ])
        ->getJson('/api/v1/staff/'.$visibleProfile->id.'/privileges')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visibleGrant->id);

    $this->actingAs($actor)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-PRV',
        ])
        ->getJson('/api/v1/staff/'.$hiddenProfile->id.'/privileges')
        ->assertNotFound();
});

it('blocks staff privilege write operations when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $actor = makeStaffPrivilegeActor([
        'staff.privileges.create',
        'staff.privileges.update',
        'staff.privileges.update-status',
    ]);
    $context = makeStaffPrivilegeContext('TPRVGRD', 'TPRV-GRD');
    $profile = makeStaffPrivilegeProfile($context['tenant']->id);
    $specialty = makeStaffPrivilegeSpecialty($context['tenant']->id, 'SP-PRV-GRD');

    $this->actingAs($actor)
        ->postJson('/api/v1/staff/'.$profile->id.'/privileges', staffPrivilegePayload(
            facilityId: $context['facility']->id,
            specialtyId: $specialty->id,
        ))
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');

    $grant = StaffPrivilegeGrantModel::query()->create([
        'staff_profile_id' => $profile->id,
        'tenant_id' => $context['tenant']->id,
        'facility_id' => $context['facility']->id,
        'specialty_id' => $specialty->id,
        'privilege_code' => 'PRV-GRD-001',
        'privilege_name' => 'Guarded Privilege',
        'scope_notes' => null,
        'granted_at' => '2026-03-01',
        'review_due_at' => '2026-09-01',
        'status' => 'active',
        'status_reason' => null,
        'granted_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$grant->id, [
            'scopeNotes' => 'Attempted guarded update',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/privileges/'.$grant->id.'/status', [
            'status' => 'suspended',
            'reason' => 'Attempted guarded status update',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});
