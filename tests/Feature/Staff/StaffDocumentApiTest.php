<?php

use App\Models\User;
use App\Modules\Staff\Infrastructure\Models\StaffDocumentAuditLogModel;
use App\Modules\Staff\Infrastructure\Models\StaffDocumentModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function makeStaffDocumentActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function makeStaffDocumentProfile(?string $tenantId = null): StaffProfileModel
{
    $targetUser = User::factory()->create();

    return StaffProfileModel::query()->create([
        'tenant_id' => $tenantId,
        'user_id' => $targetUser->id,
        'employee_number' => 'STF-DOC-'.strtoupper(Str::random(10)),
        'department' => 'Outpatient',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'LIC-DOC-'.strtoupper(Str::random(6)),
        'license_type' => 'Clinical Officer',
        'phone_extension' => '208',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);
}

/**
 * @return array<string, mixed>
 */
function staffDocumentPayload(array $overrides = []): array
{
    return array_merge([
        'documentType' => 'license_copy',
        'title' => 'Medical License',
        'description' => 'Initial license upload',
        'issuedAt' => '2025-01-01',
        'expiresAt' => '2027-01-01',
        'file' => UploadedFile::fake()->create('medical-license.pdf', 120, 'application/pdf'),
    ], $overrides);
}

it('requires authentication for staff document endpoints', function (): void {
    $profile = makeStaffDocumentProfile();

    $this->getJson('/api/v1/staff/'.$profile->id.'/documents')->assertUnauthorized();

    $this->withHeader('Accept', 'application/json')
        ->post('/api/v1/staff/'.$profile->id.'/documents', staffDocumentPayload())
        ->assertUnauthorized();
});

it('creates and updates staff documents when authorized', function (): void {
    $actor = makeStaffDocumentActor([
        'staff.documents.create',
        'staff.documents.update',
    ]);
    $profile = makeStaffDocumentProfile();

    $created = $this->actingAs($actor)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/staff/'.$profile->id.'/documents', staffDocumentPayload())
        ->assertCreated()
        ->assertJsonPath('data.title', 'Medical License')
        ->assertJsonPath('data.documentType', 'license_copy')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.verificationStatus', 'pending')
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/documents/'.$created['id'], [
            'title' => 'Updated Medical License',
            'description' => 'Updated renewal notes',
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Updated Medical License')
        ->assertJsonPath('data.description', 'Updated renewal notes');

    expect(
        StaffDocumentAuditLogModel::query()
            ->where('staff_document_id', $created['id'])
            ->where('action', 'staff-document.updated')
            ->exists()
    )->toBeTrue();
});

it('rejects verification and status lifecycle fields on document detail update endpoint', function (): void {
    $actor = makeStaffDocumentActor([
        'staff.documents.create',
        'staff.documents.update',
    ]);
    $profile = makeStaffDocumentProfile();

    $created = $this->actingAs($actor)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/staff/'.$profile->id.'/documents', staffDocumentPayload())
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/documents/'.$created['id'], [
            'title' => 'Should Not Persist',
            'status' => 'archived',
            'verificationStatus' => 'verified',
            'reason' => 'Lifecycle update attempt',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status', 'verificationStatus', 'reason']);

    $document = StaffDocumentModel::query()->findOrFail($created['id']);
    expect($document->title)->toBe('Medical License');
    expect($document->status)->toBe('active');
    expect($document->verification_status)->toBe('pending');
});

it('writes verification transition metadata in audit logs', function (): void {
    $actor = makeStaffDocumentActor([
        'staff.documents.create',
        'staff.documents.verify',
    ]);
    $profile = makeStaffDocumentProfile();

    $created = $this->actingAs($actor)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/staff/'.$profile->id.'/documents', staffDocumentPayload())
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/documents/'.$created['id'].'/verification', [
            'verificationStatus' => 'rejected',
            'reason' => 'Document is expired',
        ])
        ->assertOk()
        ->assertJsonPath('data.verificationStatus', 'rejected')
        ->assertJsonPath('data.verificationReason', 'Document is expired');

    $log = StaffDocumentAuditLogModel::query()
        ->where('staff_document_id', $created['id'])
        ->where('action', 'staff-document.verification.updated')
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull();
    expect($log?->metadata['transition']['from'] ?? null)->toBe('pending');
    expect($log?->metadata['transition']['to'] ?? null)->toBe('rejected');
    expect($log?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($log?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('writes status transition metadata in audit logs', function (): void {
    $actor = makeStaffDocumentActor([
        'staff.documents.create',
        'staff.documents.update-status',
    ]);
    $profile = makeStaffDocumentProfile();

    $created = $this->actingAs($actor)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/staff/'.$profile->id.'/documents', staffDocumentPayload())
        ->assertCreated()
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/documents/'.$created['id'].'/status', [
            'status' => 'archived',
            'reason' => 'Superseded by newer upload',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'archived')
        ->assertJsonPath('data.statusReason', 'Superseded by newer upload');

    $log = StaffDocumentAuditLogModel::query()
        ->where('staff_document_id', $created['id'])
        ->where('action', 'staff-document.status.updated')
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull();
    expect($log?->metadata['transition']['from'] ?? null)->toBe('active');
    expect($log?->metadata['transition']['to'] ?? null)->toBe('archived');
    expect($log?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($log?->metadata['reason_provided'] ?? null)->toBeTrue();
});
