<?php

use App\Models\User;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function packagingTemplateMakeUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function packagingTemplateFormularyItem(): ClinicalCatalogItemModel
{
    return ClinicalCatalogItemModel::query()->create([
        'catalog_type' => ClinicalCatalogType::FORMULARY_ITEM->value,
        'code' => 'PKG-TEST-'.uniqid(),
        'name' => 'Amoxicillin 500mg',
        'status' => 'active',
    ]);
}

it('lists, creates, and deletes packaging templates for a formulary item', function (): void {
    $user = packagingTemplateMakeUser(['platform.clinical-catalog.read', 'platform.clinical-catalog.manage-formulary']);
    $item = packagingTemplateFormularyItem();

    $this->actingAs($user)
        ->getJson("/api/v1/platform/admin/clinical-catalogs/formulary-items/{$item->id}/packaging-templates")
        ->assertOk()
        ->assertJsonPath('data', []);

    $created = $this->actingAs($user)
        ->postJson("/api/v1/platform/admin/clinical-catalogs/formulary-items/{$item->id}/packaging-templates", [
            'unit_name' => 'blister',
            'base_quantity' => 10,
            'is_base_unit' => false,
        ])
        ->assertCreated()
        ->json('data');

    expect($created)->toHaveCount(1);
    $templateId = $created[0]['id'];

    $this->actingAs($user)
        ->deleteJson("/api/v1/platform/admin/clinical-catalogs/formulary-items/{$item->id}/packaging-templates/{$templateId}")
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('blocks creating packaging templates without manage-formulary', function (): void {
    $user = packagingTemplateMakeUser(['platform.clinical-catalog.read']);
    $item = packagingTemplateFormularyItem();

    $this->actingAs($user)
        ->postJson("/api/v1/platform/admin/clinical-catalogs/formulary-items/{$item->id}/packaging-templates", [
            'unit_name' => 'blister',
            'base_quantity' => 10,
        ])
        ->assertForbidden();
});

it('creates a formulary item with the new structured clinical fields', function (): void {
    $user = packagingTemplateMakeUser([
        'platform.clinical-catalog.manage-formulary',
        'inventory.procurement.manage-compliance',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/formulary-items', [
            'code' => 'AMOX-500',
            'name' => 'Amoxicillin 500mg',
            'genericName' => 'Amoxicillin',
            'storageConditions' => 'Store below 25C',
            'requiresColdChain' => true,
            'isControlledSubstance' => true,
            'controlledSubstanceSchedule' => 'Schedule II',
            'genericGroupCode' => 'AMOXICILLIN',
        ])
        ->assertCreated();

    $response
        ->assertJsonPath('data.genericName', 'Amoxicillin')
        ->assertJsonPath('data.storageConditions', 'Store below 25C')
        ->assertJsonPath('data.requiresColdChain', true)
        ->assertJsonPath('data.isControlledSubstance', true)
        ->assertJsonPath('data.controlledSubstanceSchedule', 'Schedule II')
        ->assertJsonPath('data.genericGroupCode', 'AMOXICILLIN');
});

it('blocks setting compliance fields on create without the compliance permission', function (): void {
    $user = packagingTemplateMakeUser(['platform.clinical-catalog.manage-formulary']);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/formulary-items', [
            'code' => 'AMOX-500-B',
            'name' => 'Amoxicillin 500mg',
            'requiresColdChain' => true,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['requiresColdChain']);
});
