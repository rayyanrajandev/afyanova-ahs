<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            InitialFacilitySeeder::class,
            DskDepartmentsSeeder::class,
            DskLabClinicalCatalogSeeder::class,
            DskRadiologyClinicalCatalogSeeder::class,
            DskFormularyClinicalCatalogSeeder::class,
            DskClinicalClinicalCatalogSeeder::class,
            DskChargeableItemsSeeder::class,
            DskWarehouseSeeder::class,
            DskSuppliersSeeder::class,
            DskInventoryItemsSeeder::class,
            DskClinicalCatalogConsumptionRecipeSeeder::class,
        ]);
    }
}
