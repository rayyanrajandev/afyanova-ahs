<?php

namespace Database\Seeders;

use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use Database\Seeders\Support\FacilitySubscriptionBootstrap;
use Illuminate\Database\Seeder;

class InitialFacilitySeeder extends Seeder
{
    public function run(): void
    {
        $tenant = TenantModel::firstOrCreate(
            ['code' => 'DSK'],
            [
                'name' => 'DSK Dispensary Ltd',
                'country_code' => 'TZ',
                'status' => 'active',
            ],
        );

        $facility = FacilityModel::firstOrCreate(
            ['code' => 'DSK'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'DSK Dispensary',
                'facility_type' => 'dispensary',
                'timezone' => 'Africa/Dar_es_Salaam',
                'status' => 'active',
            ],
        );

        FacilitySubscriptionBootstrap::ensureActiveSubscription($tenant->id, $facility->id);

        $this->command?->info('Tenant and facility created: DSK Dispensary (Mikwambe, Toangoma, Temeke District, Dar es Salaam, Tanzania).');
    }
}
