<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(function (): void {
        $this->withoutMiddleware([
            EnsureMappedFacilitySubscriptionEntitlement::class,
            EnsureFacilitySubscriptionEntitlement::class,
        ]);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a minimal active test role for route-authorization tests.
 */
function createTestRole(): \App\Modules\Platform\Infrastructure\Models\RoleModel
{
    /** @var \App\Modules\Platform\Infrastructure\Models\RoleModel $role */
    $role = \App\Modules\Platform\Infrastructure\Models\RoleModel::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'code' => 'TEST-ROLE',
        'name' => 'Test Role',
        'status' => 'active',
        'effective_from' => now(),
    ]);

    return $role;
}

/**
 * Create a verified user with an active test role and the given permissions.
 */
function makeUserWithRole(array $permissions = []): \App\Models\User
{
    $role = createTestRole();

    /** @var \App\Models\User $user */
    $user = \App\Models\User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $user->roles()->attach($role->id);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * Phase 1 RBAC test helpers - shared across inventory procurement tests
 */
function createRbacScope(): array
{
    $tenant = \App\Modules\Platform\Infrastructure\Models\TenantModel::create([
        'code' => 'RBAC-TENANT',
        'name' => 'RBAC Test Tenant',
        'country_code' => 'TZ',
    ]);
    $facility = \App\Modules\Platform\Infrastructure\Models\FacilityModel::create([
        'tenant_id' => $tenant->id,
        'code' => 'RBAC-FACILITY',
        'name' => 'RBAC Test Facility',
        'facility_type' => 'Hospital',
        'status' => 'active',
    ]);
    $department = \App\Modules\Department\Infrastructure\Models\DepartmentModel::create([
        'tenant_id' => $tenant->id,
        'facility_id' => $facility->id,
        'code' => 'RBAC-DEPT-LAB',
        'name' => 'RBAC Lab Department',
        'status' => 'active',
    ]);
    $warehouse = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel::create([
        'tenant_id' => $tenant->id,
        'facility_id' => $facility->id,
        'department_id' => $department->id,
        'warehouse_code' => 'RBAC-WH-LAB',
        'warehouse_name' => 'RBAC Lab Warehouse',
        'status' => 'active',
        'is_default' => true,
    ]);

    return [
        'tenant' => $tenant,
        'facility' => $facility,
        'department' => $department,
        'warehouse' => $warehouse,
    ];
}

function createInventoryItems(array $scope, int $count = 3): array
{
    $items = [];
    for ($i = 0; $i < $count; $i++) {
        $items[] = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel::create([
            'tenant_id' => $scope['tenant']->id,
            'facility_id' => $scope['facility']->id,
            'default_warehouse_id' => $scope['warehouse']->id,
            'item_code' => 'RBAC-ITM-'.strtoupper(\Illuminate\Support\Str::random(8)),
            'item_name' => 'RBAC Test Item '.($i + 1),
            'category' => 'medical_consumable',
            'unit' => 'box',
            'current_stock' => 100,
            'reorder_level' => 10,
            'max_stock_level' => 100,
            'status' => 'active',
        ]);
    }
    return $items;
}

function scopeHeaders(array $scope): array
{
    return [
        'X-Tenant-Code' => $scope['tenant']->code,
        'X-Facility-Code' => $scope['facility']->code,
    ];
}

/**
 * @return array<string, mixed>
 */
function clinicalRecipeStockInventoryItem(array $overrides = []): array
{
    $item = array_merge([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'tenant_id' => null,
        'facility_id' => null,
        'item_code' => 'STK-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
        'item_name' => 'Recipe consumable stock',
        'category' => 'medical_consumable',
        'subcategory' => 'procedure_consumable',
        'unit' => 'unit',
        'current_stock' => 10,
        'reorder_level' => 2,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    \Illuminate\Support\Facades\DB::table('inventory_items')->insert($item);

    return $item;
}

/**
 * @return array<string, mixed>
 */
function clinicalRecipeStockRecipeLine(
    string $catalogItemId,
    string $inventoryItemId,
    array $overrides = [],
): array {
    $line = array_merge([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'tenant_id' => null,
        'facility_id' => null,
        'clinical_catalog_item_id' => $catalogItemId,
        'inventory_item_id' => $inventoryItemId,
        'quantity_per_order' => 1,
        'unit' => 'unit',
        'waste_factor_percent' => 0,
        'consumption_stage' => 'per_order',
        'is_active' => true,
        'notes' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    \Illuminate\Support\Facades\DB::table('clinical_catalog_consumption_recipe_items')->insert($line);

    return $line;
}

/**
 * @return array<string, mixed>
 */
function inventoryBatchRecord(string $itemId, array $overrides = []): array
{
    $batch = array_merge([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'tenant_id' => null,
        'facility_id' => null,
        'item_id' => $itemId,
        'batch_number' => 'BATCH-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
        'lot_number' => null,
        'manufacture_date' => now()->subMonths(2)->toDateString(),
        'expiry_date' => now()->addMonths(6)->toDateString(),
        'quantity' => 10,
        'warehouse_id' => null,
        'bin_location' => null,
        'supplier_id' => null,
        'unit_cost' => null,
        'status' => 'available',
        'notes' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    \Illuminate\Support\Facades\DB::table('inventory_batches')->insert($batch);

    return $batch;
}

/**
 * Phase 5 readiness checks validate file presence by path. Tests create these
 * fixtures explicitly so local developer document folders are not test inputs.
 *
 * @return array<int, string>
 */
function phase5TestingEnsureConfiguredReadinessFiles(): array
{
    $createdPaths = [];

    foreach (phase5TestingConfiguredReadinessFilePaths() as $relativePath) {
        $absolutePath = base_path($relativePath);

        if (is_file($absolutePath)) {
            continue;
        }

        $directory = dirname($absolutePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolutePath, "# Phase 5 readiness test fixture\n");
        $createdPaths[] = $absolutePath;
    }

    return $createdPaths;
}

/**
 * @param  array<int, string>  $createdPaths
 */
function phase5TestingRemoveConfiguredReadinessFiles(array $createdPaths): void
{
    foreach (array_reverse($createdPaths) as $absolutePath) {
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        phase5TestingRemoveEmptyReadinessDirectories(dirname($absolutePath));
    }
}

/**
 * @return array<int, string>
 */
function phase5TestingConfiguredReadinessFilePaths(): array
{
    $paths = [];

    foreach ((array) config('phase5_readiness.gates', []) as $gate) {
        if (is_array($gate) && is_string($gate['signedArtifact'] ?? null)) {
            $paths[] = $gate['signedArtifact'];
        }
    }

    foreach ((array) config('phase5_documentation_readiness.modules', []) as $module) {
        if (! is_array($module)) {
            continue;
        }

        foreach ((array) ($module['requiredFiles'] ?? []) as $path) {
            if (is_string($path)) {
                $paths[] = $path;
            }
        }

        if (is_string($module['citationPackPath'] ?? null)) {
            $paths[] = $module['citationPackPath'];
        }
    }

    return array_values(array_unique(array_filter(
        array_map(static fn (string $path): string => trim($path), $paths),
        static fn (string $path): bool => $path !== ''
    )));
}

function phase5TestingRemoveEmptyReadinessDirectories(string $directory): void
{
    $documentsRoot = rtrim(str_replace('\\', '/', base_path('documents')), '/');
    $current = rtrim(str_replace('\\', '/', $directory), '/');

    while (
        $current !== ''
        && $current !== $documentsRoot
        && str_starts_with($current, $documentsRoot.'/')
        && is_dir($current)
    ) {
        $entries = array_values(array_diff(scandir($current) ?: [], ['.', '..']));
        if ($entries !== []) {
            return;
        }

        @rmdir($current);
        $current = rtrim(str_replace('\\', '/', dirname($current)), '/');
    }
}
