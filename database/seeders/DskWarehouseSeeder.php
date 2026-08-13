<?php

namespace Database\Seeders;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use Illuminate\Database\Seeder;

class DskWarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $facility = FacilityModel::where('code', 'DSK')->first();

        if (!$facility) {
            $this->command?->error('DSK facility not found. Run InitialFacilitySeeder first.');
            return;
        }

        $deptId = DepartmentModel::where('facility_id', $facility->id)->where('code', 'PHA')->value('id');

        if (!$deptId) {
            $this->command?->error('PHA department not found for DSK. Run DskDepartmentsSeeder first.');
            return;
        }

        $warehouse = InventoryWarehouseModel::firstOrCreate(
            [
                'facility_id' => $facility->id,
                'warehouse_code' => 'MAIN-PHARMACY',
            ],
            [
                'tenant_id' => $facility->tenant_id,
                'warehouse_name' => 'Main Pharmacy',
                'warehouse_type' => 'pharmacy',
                'department_id' => $deptId,
                'location' => 'DSK Dispensary, Mikwambe, Toangoma',
                'contact_person' => 'Pharmacist In Charge',
                'status' => 'active',
                'is_default' => true,
            ],
        );

        $this->command?->info("Warehouse '{$warehouse->warehouse_name}' ({$warehouse->warehouse_code}) seeded for DSK Dispensary.");
    }
}
