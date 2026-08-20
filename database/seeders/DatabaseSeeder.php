<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(InitialFacilitySeeder::class);

        // Synchronize all platform and facility roles & permissions from config/roles.php
        $this->command?->call('roles:sync');

        $this->call([
            DskDepartmentsSeeder::class,
            DskLabClinicalCatalogSeeder::class,
            DskRadiologyClinicalCatalogSeeder::class,
            DskFormularyClinicalCatalogSeeder::class,
            DskFormularyPackagingTemplateSeeder::class,
            DskClinicalClinicalCatalogSeeder::class,
            DskChargeableItemsSeeder::class,
            DskWarehouseSeeder::class,
            DskSuppliersSeeder::class,
            DskInventoryItemsSeeder::class,
            DskClinicalCatalogConsumptionRecipeSeeder::class,
            DskStaffSeeder::class,
            WorkspaceTestUsersSeeder::class,
        ]);
    }
}
