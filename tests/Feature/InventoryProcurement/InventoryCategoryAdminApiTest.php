<?php

use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryCategoryModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function categoryAdminMakeUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

it('lists categories with their subcategories', function (): void {
    $user = categoryAdminMakeUser(['inventory.procurement.manage-items']);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/categories')
        ->assertOk();

    expect($response->json('data'))->not->toBeEmpty();
    expect($response->json('data.0'))->toHaveKeys(['id', 'code', 'label', 'subcategories']);
});

it('blocks category admin endpoints for users without manage-items', function (): void {
    $user = categoryAdminMakeUser(['inventory.procurement.read']);

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-procurement/categories')
        ->assertForbidden();
});

it('updates a category label, description, active flag, and sort order', function (): void {
    $user = categoryAdminMakeUser(['inventory.procurement.manage-items']);
    $category = InventoryCategoryModel::query()->firstOrFail();

    $this->actingAs($user)
        ->patchJson("/api/v1/inventory-procurement/categories/{$category->id}", [
            'label' => 'Renamed Category',
            'description' => 'Updated description',
            'isActive' => false,
            'sortOrder' => 99,
        ])
        ->assertOk()
        ->assertJsonPath('data.label', 'Renamed Category')
        ->assertJsonPath('data.description', 'Updated description')
        ->assertJsonPath('data.isActive', false)
        ->assertJsonPath('data.sortOrder', 99);
});

it('creates a subcategory under a category', function (): void {
    $user = categoryAdminMakeUser(['inventory.procurement.manage-items']);
    $category = InventoryCategoryModel::query()->firstOrFail();

    $response = $this->actingAs($user)
        ->postJson("/api/v1/inventory-procurement/categories/{$category->id}/subcategories", [
            'code' => 'test-subcategory',
            'label' => 'Test Subcategory',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'test-subcategory')
        ->assertJsonPath('data.label', 'Test Subcategory')
        ->assertJsonPath('data.isActive', true);

    expect($response->json('data.categoryId'))->toBe($category->id);
});

it('rejects a duplicate subcategory code within the same category', function (): void {
    $user = categoryAdminMakeUser(['inventory.procurement.manage-items']);
    $category = InventoryCategoryModel::query()->firstOrFail();

    $this->actingAs($user)
        ->postJson("/api/v1/inventory-procurement/categories/{$category->id}/subcategories", [
            'code' => 'dup-code',
            'label' => 'First',
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->postJson("/api/v1/inventory-procurement/categories/{$category->id}/subcategories", [
            'code' => 'DUP-CODE',
            'label' => 'Second',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

it('updates and deactivates a subcategory', function (): void {
    $user = categoryAdminMakeUser(['inventory.procurement.manage-items']);
    $category = InventoryCategoryModel::query()->firstOrFail();

    $subcategoryId = $this->actingAs($user)
        ->postJson("/api/v1/inventory-procurement/categories/{$category->id}/subcategories", [
            'code' => 'toggle-me',
            'label' => 'Toggle Me',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($user)
        ->patchJson("/api/v1/inventory-procurement/categories/{$category->id}/subcategories/{$subcategoryId}", [
            'label' => 'Renamed',
            'isActive' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.label', 'Renamed')
        ->assertJsonPath('data.isActive', false);
});
