<?php

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\FacilityConfigurationAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\FacilitySubscriptionModel;
use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function makeFacilityConfigurationActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @return array{tenant: TenantModel, facility: FacilityModel}
 */
function makeFacilityConfigurationContext(
    string $tenantCode = 'TEN-FCFG',
    string $facilityCode = 'FAC-FCFG',
    ?array $allowedCountryCodes = null
): array
{
    $tenant = TenantModel::query()->firstOrCreate(
        ['code' => strtoupper($tenantCode)],
        [
            'name' => 'Facility Config Tenant '.strtoupper($tenantCode),
            'country_code' => 'TZ',
            'allowed_country_codes' => $allowedCountryCodes,
            'status' => 'active',
        ],
    );

    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => strtoupper($facilityCode),
        'name' => 'Facility '.strtoupper($facilityCode),
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    return [
        'tenant' => $tenant,
        'facility' => $facility,
    ];
}

it('requires authentication for facility configuration endpoints', function (): void {
    $context = makeFacilityConfigurationContext();

    $this->getJson('/api/v1/platform/admin/facilities')->assertUnauthorized();

    $this->patchJson('/api/v1/platform/admin/facilities/'.$context['facility']->id, [
        'name' => 'Renamed Facility',
    ])->assertUnauthorized();
});

it('forbids facility configuration endpoints without required permissions', function (): void {
    $actor = makeFacilityConfigurationActor();
    $context = makeFacilityConfigurationContext('TEN-NP', 'FAC-NP');

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/facilities')
        ->assertForbidden();

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facilities/'.$context['facility']->id, [
            'name' => 'Should Not Update',
        ])
        ->assertForbidden();
});

it('lists and shows facilities when read permission is granted', function (): void {
    $actor = makeFacilityConfigurationActor(['platform.facilities.read']);
    $context = makeFacilityConfigurationContext('TEN-READ', 'FAC-READ');

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/facilities?q=FAC-READ')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $context['facility']->id)
        ->assertJsonPath('data.0.code', 'FAC-READ');

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/facilities/'.$context['facility']->id)
        ->assertOk()
        ->assertJsonPath('data.id', $context['facility']->id)
        ->assertJsonPath('data.tenantCode', 'TEN-READ')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.operationsOwnerUserId', null);
});

it('updates facility configuration and writes audit logs when authorized', function (): void {
    $actor = makeFacilityConfigurationActor(['platform.facilities.update']);
    $context = makeFacilityConfigurationContext('TEN-UPD', 'FAC-UPD');

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facilities/'.$context['facility']->id, [
            'code' => 'fac-upd-new',
            'name' => 'Updated Facility Name',
            'facilityType' => 'clinic',
            'timezone' => 'Africa/Nairobi',
        ])
        ->assertOk()
        ->assertJsonPath('data.code', 'FAC-UPD-NEW')
        ->assertJsonPath('data.name', 'Updated Facility Name')
        ->assertJsonPath('data.facilityType', 'clinic')
        ->assertJsonPath('data.timezone', 'Africa/Nairobi');

    expect(
        FacilityModel::query()
            ->where('id', $context['facility']->id)
            ->where('code', 'FAC-UPD-NEW')
            ->where('facility_type', 'clinic')
            ->exists()
    )->toBeTrue();

    expect(
        FacilityConfigurationAuditLogModel::query()
            ->where('facility_id', $context['facility']->id)
            ->where('action', 'platform.facilities.updated')
            ->exists()
    )->toBeTrue();
});

it('updates tenant country policy through facility configuration and writes audit logs', function (): void {
    $actor = makeFacilityConfigurationActor(['platform.facilities.update']);
    $context = makeFacilityConfigurationContext('TEN-POL', 'FAC-POL', ['TZ']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facilities/'.$context['facility']->id, [
            'tenantAllowedCountryCodes' => ['ke', 'ug'],
        ])
        ->assertOk()
        ->assertJsonPath('data.tenantCode', 'TEN-POL')
        ->assertJsonPath('data.tenantAllowedCountryCodes.0', 'KE')
        ->assertJsonPath('data.tenantAllowedCountryCodes.1', 'UG');

    $context['tenant']->refresh();

    expect($context['tenant']->allowed_country_codes)->toBe(['KE', 'UG']);

    $log = FacilityConfigurationAuditLogModel::query()
        ->where('facility_id', $context['facility']->id)
        ->where('action', 'platform.facilities.tenant-country-policy.updated')
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull();
    expect($log?->metadata['tenant_code'] ?? null)->toBe('TEN-POL');
    expect($log?->changes['tenant_allowed_country_codes']['before'] ?? null)->toBe(['TZ']);
    expect($log?->changes['tenant_allowed_country_codes']['after'] ?? null)->toBe(['KE', 'UG']);
});

it('rejects duplicate facility code update in the same tenant', function (): void {
    $actor = makeFacilityConfigurationActor(['platform.facilities.update']);
    $contextA = makeFacilityConfigurationContext('TEN-DUP', 'FAC-DUP-A');
    $contextB = makeFacilityConfigurationContext('TEN-DUP', 'FAC-DUP-B');

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facilities/'.$contextB['facility']->id, [
            'code' => 'fac-dup-a',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    expect(
        FacilityModel::query()
            ->where('id', $contextB['facility']->id)
            ->where('code', 'FAC-DUP-B')
            ->exists()
    )->toBeTrue();
});

it('updates facility status with reason validation and writes audit logs', function (): void {
    $actor = makeFacilityConfigurationActor(['platform.facilities.update-status']);
    $context = makeFacilityConfigurationContext('TEN-STS', 'FAC-STS');

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facilities/'.$context['facility']->id.'/status', [
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facilities/'.$context['facility']->id.'/status', [
            'status' => 'inactive',
            'reason' => 'Temporary operational suspension for compliance review.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.statusReason', 'Temporary operational suspension for compliance review.');

    $log = FacilityConfigurationAuditLogModel::query()
        ->where('facility_id', $context['facility']->id)
        ->where('action', 'platform.facilities.status.updated')
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull();
    expect($log?->metadata['transition']['from'] ?? null)->toBe('active');
    expect($log?->metadata['transition']['to'] ?? null)->toBe('inactive');
    expect($log?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($log?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('rejects lifecycle status fields on facility detail update endpoint', function (): void {
    $actor = makeFacilityConfigurationActor(['platform.facilities.update']);
    $context = makeFacilityConfigurationContext('TEN-UPD-GUARD', 'FAC-UPD-GUARD');

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facilities/'.$context['facility']->id, [
            'name' => 'Should Not Persist',
            'status' => 'inactive',
            'reason' => 'Must use dedicated status endpoint',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    $context['facility']->refresh();
    expect($context['facility']->name)->toBe('Facility FAC-UPD-GUARD');
    expect($context['facility']->status)->toBe('active');
});

it('syncs facility owners and writes audit logs when authorized', function (): void {
    $actor = makeFacilityConfigurationActor(['platform.facilities.manage-owners']);
    $context = makeFacilityConfigurationContext('TEN-OWN', 'FAC-OWN');

    $operationsOwner = User::factory()->create();
    $clinicalOwner = User::factory()->create();
    $administrativeOwner = User::factory()->create();

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facilities/'.$context['facility']->id.'/owners', [
            'operationsOwnerUserId' => $operationsOwner->id,
            'clinicalOwnerUserId' => $clinicalOwner->id,
            'administrativeOwnerUserId' => $administrativeOwner->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.operationsOwnerUserId', $operationsOwner->id)
        ->assertJsonPath('data.clinicalOwnerUserId', $clinicalOwner->id)
        ->assertJsonPath('data.administrativeOwnerUserId', $administrativeOwner->id);

    expect(
        FacilityModel::query()
            ->where('id', $context['facility']->id)
            ->where('operations_owner_user_id', $operationsOwner->id)
            ->where('clinical_owner_user_id', $clinicalOwner->id)
            ->where('administrative_owner_user_id', $administrativeOwner->id)
            ->exists()
    )->toBeTrue();

    expect(
        FacilityConfigurationAuditLogModel::query()
            ->where('facility_id', $context['facility']->id)
            ->where('action', 'platform.facilities.owners.synced')
            ->exists()
    )->toBeTrue();
});

it('creates a facility with a new facility admin and generates an invite link', function (): void {
    app()->detectEnvironment(static fn (): string => 'local');
    config()->set('mail.default', 'smtp');

    $actor = makeFacilityConfigurationActor(['platform.facilities.create']);

    $facilityAdminRole = RoleModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
            'code' => 'ADMIN.FACILITY',
        'name' => 'Facility Administrator',
        'status' => 'active',
        'is_system' => true,
    ]);

    $response = $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/facilities', [
            'tenantCode' => 'dsk',
            'tenantName' => 'DSK Dispensary Group',
            'tenantCountryCode' => 'TZ',
            'tenantAllowedCountryCodes' => ['TZ'],
            'facilityCode' => 'dsk-disp',
            'facilityName' => 'DSK Dispensary',
            'facilityType' => 'dispensary',
            'facilityTier' => 'primary_care',
            'timezone' => 'Africa/Dar_es_Salaam',
            'facilityAdmin' => [
                'name' => 'DSK Facility Admin',
                'email' => 'facility.admin@dsk.test',
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'DSK-DISP')
        ->assertJsonPath('data.name', 'DSK Dispensary')
        ->assertJsonPath('meta.facilityAdminInvite.deliveryMode', 'local-preview');

    expect((string) data_get($response->json(), 'meta.facilityAdminInvite.previewUrl'))
        ->toContain('/reset-password/');

    $admin = User::query()
        ->where('email', 'facility.admin@dsk.test')
        ->first();

    expect($admin)->not->toBeNull();
    expect($admin?->email_verified_at)->toBeNull();
    expect($admin?->roles()->where('roles.id', $facilityAdminRole->id)->exists())->toBeTrue();

    $facility = FacilityModel::query()
        ->where('code', 'DSK-DISP')
        ->first();

    expect($facility)->not->toBeNull();
    expect($facility?->administrative_owner_user_id)->toBe($admin?->id);

    expect(DB::table('facility_user')
        ->where('facility_id', $facility?->id)
        ->where('user_id', $admin?->id)
        ->where('role', 'facility_admin')
        ->where('is_primary', true)
        ->where('is_active', true)
        ->exists())->toBeTrue();

    expect(DB::table('password_reset_tokens')
        ->where('email', 'facility.admin@dsk.test')
        ->exists())->toBeTrue();
});

it('reports expired active facility subscriptions as restricted access', function (): void {
    $actor = makeFacilityConfigurationActor(['platform.facilities.read']);
    $context = makeFacilityConfigurationContext('TEN-SUB', 'FAC-SUB');
    $plan = PlatformSubscriptionPlanModel::query()
        ->where('code', 'patient_registration')
        ->firstOrFail();

    FacilitySubscriptionModel::query()->create([
        'tenant_id' => $context['tenant']->id,
        'facility_id' => $context['facility']->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'billing_cycle' => 'monthly',
        'price_amount' => $plan->price_amount,
        'currency_code' => $plan->currency_code,
        'current_period_starts_at' => now()->subMonth(),
        'current_period_ends_at' => now()->subDay(),
        'metadata' => [],
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/facilities/'.$context['facility']->id.'/subscription')
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.accessEnabled', false)
        ->assertJsonPath('data.accessState', 'expired');
});

it('lists and exports facility configuration audit logs when authorized', function (): void {
    $actor = makeFacilityConfigurationActor([
        'platform.facilities.update',
        'platform.facilities.view-audit-logs',
    ]);
    $context = makeFacilityConfigurationContext('TEN-AUD', 'FAC-AUD');

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facilities/'.$context['facility']->id, [
            'name' => 'Audit Trail Facility',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/facilities/'.$context['facility']->id.'/audit-logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'platform.facilities.updated');

    $exportResponse = $this->actingAs($actor)
        ->get('/api/v1/platform/admin/facilities/'.$context['facility']->id.'/audit-logs/export')
        ->assertOk()
        ->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');

    expect($exportResponse->streamedContent())->toContain('createdAt,action,actorType,actorId,changes,metadata');
});
