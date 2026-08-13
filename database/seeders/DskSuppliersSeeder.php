<?php

namespace Database\Seeders;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventorySupplierModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use Illuminate\Database\Seeder;

class DskSuppliersSeeder extends Seeder
{
    public function run(): void
    {
        $facility = FacilityModel::where('code', 'DSK')->first();

        if (!$facility) {
            $this->command?->error('DSK facility not found. Run InitialFacilitySeeder first.');
            return;
        }

        $suppliers = [
            [
                'supplier_code' => 'MED-001',
                'supplier_name' => 'Medical Distributors Ltd',
                'tin_number' => '100-123-456',
                'contact_person' => 'John Mwangi',
                'phone' => '+255 712 345 678',
                'email' => 'info@medicaldistributors.co.tz',
                'address_line' => 'Plot 45, Mwai Kibaki Rd, Dar es Salaam',
                'country_code' => 'TZ',
            ],
            [
                'supplier_code' => 'PHA-001',
                'supplier_name' => 'PharmaCare Tanzania Ltd',
                'tin_number' => '100-789-012',
                'contact_person' => 'Amina Hassan',
                'phone' => '+255 715 987 654',
                'email' => 'orders@pharmacaretz.com',
                'address_line' => 'Block C, Nyerere Road, Dar es Salaam',
                'country_code' => 'TZ',
            ],
            [
                'supplier_code' => 'AFY-001',
                'supplier_name' => 'Afya Supply Co. Ltd',
                'tin_number' => '100-345-678',
                'contact_person' => 'David Mushi',
                'phone' => '+255 765 432 101',
                'email' => 'sales@afyasupply.co.tz',
                'address_line' => 'Suite 7, Kariakoo Market, Dar es Salaam',
                'country_code' => 'TZ',
            ],
        ];

        $count = 0;

        foreach ($suppliers as $data) {
            InventorySupplierModel::firstOrCreate(
                [
                    'facility_id' => $facility->id,
                    'supplier_code' => $data['supplier_code'],
                ],
                [
                    'tenant_id' => $facility->tenant_id,
                    'supplier_name' => $data['supplier_name'],
                    'tin_number' => $data['tin_number'],
                    'contact_person' => $data['contact_person'],
                    'phone' => $data['phone'],
                    'email' => $data['email'],
                    'address_line' => $data['address_line'],
                    'country_code' => $data['country_code'],
                    'status' => 'active',
                ],
            );
            $count++;
        }

        $this->command?->info("Seeded {$count} suppliers for DSK Dispensary.");
    }
}
