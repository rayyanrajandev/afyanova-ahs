<?php

namespace Database\Seeders;

use App\Modules\Patient\Application\Services\PatientMrnGenerator;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DskPatientsSeeder extends Seeder
{
    public function run(): void
    {
        $facility = FacilityModel::where('code', 'DSK')->first();

        if (! $facility) {
            $this->command?->error('DSK facility not found. Run InitialFacilitySeeder first.');

            return;
        }

        $tenantId = $facility->tenant_id;
        $mrnGenerator = app(PatientMrnGenerator::class);

        $patientsData = [
            [
                'first_name' => 'Juma',
                'middle_name' => 'Ramadhani',
                'last_name' => 'Bakari',
                'gender' => 'male',
                'date_of_birth' => '1985-03-15',
                'phone' => '+255714123456',
                'national_id' => '19850315111010000101',
                'district' => 'Temeke',
                'address_line' => 'Toangoma, Mikwambe',
                'next_of_kin_name' => 'Asha Bakari',
                'next_of_kin_phone' => '+255714987654',
            ],
            [
                'first_name' => 'Asha',
                'middle_name' => 'Salum',
                'last_name' => 'Mussa',
                'gender' => 'female',
                'date_of_birth' => '1992-07-22',
                'phone' => '+255754234567',
                'national_id' => '19920722111020000102',
                'district' => 'Temeke',
                'address_line' => 'Mbagala Rangi Tatu',
                'next_of_kin_name' => 'Salum Mussa',
                'next_of_kin_phone' => '+255754876543',
            ],
            [
                'first_name' => 'Baraka',
                'middle_name' => 'Emmanuel',
                'last_name' => 'Mwangi',
                'gender' => 'male',
                'date_of_birth' => '1990-11-05',
                'phone' => '+255784345678',
                'national_id' => '19901105111010000103',
                'district' => 'Temeke',
                'address_line' => 'Kongowe Mwisho',
                'next_of_kin_name' => 'Neema Mwangi',
                'next_of_kin_phone' => '+255784765432',
            ],
            [
                'first_name' => 'Rehema',
                'middle_name' => 'Said',
                'last_name' => 'Kitwana',
                'gender' => 'female',
                'date_of_birth' => '1996-02-18',
                'phone' => '+255762456789',
                'national_id' => '19960218111020000104',
                'district' => 'Kigamboni',
                'address_line' => 'Tuangoma Shuleni',
                'next_of_kin_name' => 'Said Kitwana',
                'next_of_kin_phone' => '+255762654321',
            ],
            [
                'first_name' => 'Joseph',
                'middle_name' => 'Peter',
                'last_name' => 'Mallya',
                'gender' => 'male',
                'date_of_birth' => '1978-09-30',
                'phone' => '+255713567890',
                'national_id' => '19780930111010000105',
                'district' => 'Temeke',
                'address_line' => 'Chamazi Dovya',
                'next_of_kin_name' => 'Grace Mallya',
                'next_of_kin_phone' => '+255713543210',
            ],
            [
                'first_name' => 'Fatuma',
                'middle_name' => 'Ally',
                'last_name' => 'Mwinyi',
                'gender' => 'female',
                'date_of_birth' => '2000-05-14',
                'phone' => '+255755678901',
                'national_id' => '20000514111020000106',
                'district' => 'Temeke',
                'address_line' => 'Toangoma Goroka',
                'next_of_kin_name' => 'Ally Mwinyi',
                'next_of_kin_phone' => '+255755432109',
            ],
            [
                'first_name' => 'Kelvin',
                'middle_name' => 'Godfrey',
                'last_name' => 'Mushi',
                'gender' => 'male',
                'date_of_birth' => '1994-12-08',
                'phone' => '+255786789012',
                'national_id' => '19941208111010000107',
                'district' => 'Ilala',
                'address_line' => 'Kariakoo Msimbazi',
                'next_of_kin_name' => 'Godfrey Mushi',
                'next_of_kin_phone' => '+255786321098',
            ],
            [
                'first_name' => 'Mwajuma',
                'middle_name' => 'Hassan',
                'last_name' => 'Kondo',
                'gender' => 'female',
                'date_of_birth' => '1982-08-25',
                'phone' => '+255767890123',
                'national_id' => '19820825111020000108',
                'district' => 'Temeke',
                'address_line' => 'Vikindu Kambi',
                'next_of_kin_name' => 'Hassan Kondo',
                'next_of_kin_phone' => '+255767210987',
            ],
            [
                'first_name' => 'Daudi',
                'middle_name' => 'Lucas',
                'last_name' => 'Tarimo',
                'gender' => 'male',
                'date_of_birth' => '1987-04-19',
                'phone' => '+255715901234',
                'national_id' => '19870419111010000109',
                'district' => 'Temeke',
                'address_line' => 'Yombo Vituka',
                'next_of_kin_name' => 'Agnes Tarimo',
                'next_of_kin_phone' => '+255715109876',
            ],
            [
                'first_name' => 'Amina',
                'middle_name' => 'Omary',
                'last_name' => 'Mshana',
                'gender' => 'female',
                'date_of_birth' => '1998-10-03',
                'phone' => '+255756012345',
                'national_id' => '19981003111020000110',
                'district' => 'Kigamboni',
                'address_line' => 'Mjimwema Centre',
                'next_of_kin_name' => 'Omary Mshana',
                'next_of_kin_phone' => '+255756098765',
            ],
            [
                'first_name' => 'Godfrey',
                'middle_name' => 'Charles',
                'last_name' => 'Mtweve',
                'gender' => 'male',
                'date_of_birth' => '1975-01-11',
                'phone' => '+255787123450',
                'national_id' => '19750111111010000111',
                'district' => 'Temeke',
                'address_line' => 'Kurasini Shimo la Udongo',
                'next_of_kin_name' => 'Charles Mtweve',
                'next_of_kin_phone' => '+255787987650',
            ],
            [
                'first_name' => 'Halima',
                'middle_name' => 'Juma',
                'last_name' => 'Kibwana',
                'gender' => 'female',
                'date_of_birth' => '1991-06-29',
                'phone' => '+255768234501',
                'national_id' => '19910629111020000112',
                'district' => 'Temeke',
                'address_line' => 'Buza Kanisani',
                'next_of_kin_name' => 'Juma Kibwana',
                'next_of_kin_phone' => '+255768876501',
            ],
            [
                'first_name' => 'Said',
                'middle_name' => 'Abdallah',
                'last_name' => 'Mkumbo',
                'gender' => 'male',
                'date_of_birth' => '1980-09-17',
                'phone' => '+255716345012',
                'national_id' => '19800917111010000113',
                'district' => 'Temeke',
                'address_line' => 'Mbagala Majimatitu',
                'next_of_kin_name' => 'Khadija Mkumbo',
                'next_of_kin_phone' => '+255716765012',
            ],
            [
                'first_name' => 'Mariam',
                'middle_name' => 'Shabani',
                'last_name' => 'Chambo',
                'gender' => 'female',
                'date_of_birth' => '1995-03-04',
                'phone' => '+255757450123',
                'national_id' => '19950304111020000114',
                'district' => 'Temeke',
                'address_line' => 'Toangoma Masantula',
                'next_of_kin_name' => 'Shabani Chambo',
                'next_of_kin_phone' => '+255757650123',
            ],
            [
                'first_name' => 'Emmanuel',
                'middle_name' => 'Simon',
                'last_name' => 'Masanja',
                'gender' => 'male',
                'date_of_birth' => '1989-12-21',
                'phone' => '+255788501234',
                'national_id' => '19891221111010000115',
                'district' => 'Ilala',
                'address_line' => 'Gerezani Reli',
                'next_of_kin_name' => 'Simon Masanja',
                'next_of_kin_phone' => '+255788543210',
            ],
            [
                'first_name' => 'Zuhura',
                'middle_name' => 'Kassim',
                'last_name' => 'Mndeme',
                'gender' => 'female',
                'date_of_birth' => '1993-08-14',
                'phone' => '+255769612345',
                'national_id' => '19930814111020000116',
                'district' => 'Temeke',
                'address_line' => 'Mtoni Kijichi',
                'next_of_kin_name' => 'Kassim Mndeme',
                'next_of_kin_phone' => '+255769432109',
            ],
            [
                'first_name' => 'Frank',
                'middle_name' => 'Christopher',
                'last_name' => 'Lyimo',
                'gender' => 'male',
                'date_of_birth' => '1984-05-27',
                'phone' => '+255717723456',
                'national_id' => '19840527111010000117',
                'district' => 'Temeke',
                'address_line' => 'Tandika sokoni',
                'next_of_kin_name' => 'Christopher Lyimo',
                'next_of_kin_phone' => '+255717321098',
            ],
            [
                'first_name' => 'Pendo',
                'middle_name' => 'Jackson',
                'last_name' => 'Msangi',
                'gender' => 'female',
                'date_of_birth' => '1997-11-30',
                'phone' => '+255758834567',
                'national_id' => '19971130111020000118',
                'district' => 'Kigamboni',
                'address_line' => 'Gezaulole',
                'next_of_kin_name' => 'Jackson Msangi',
                'next_of_kin_phone' => '+255758210987',
            ],
            [
                'first_name' => 'Rashid',
                'middle_name' => 'Selemani',
                'last_name' => 'Mrema',
                'gender' => 'male',
                'date_of_birth' => '1979-10-10',
                'phone' => '+255789945678',
                'national_id' => '19791010111010000119',
                'district' => 'Temeke',
                'address_line' => 'Charambe Mwandege',
                'next_of_kin_name' => 'Selemani Mrema',
                'next_of_kin_phone' => '+255789109876',
            ],
            [
                'first_name' => 'Happiness',
                'middle_name' => 'Daniel',
                'last_name' => 'Shirima',
                'gender' => 'female',
                'date_of_birth' => '2001-01-24',
                'phone' => '+255760056789',
                'national_id' => '20010124111020000120',
                'district' => 'Temeke',
                'address_line' => 'Toangoma Mwanagati',
                'next_of_kin_name' => 'Daniel Shirima',
                'next_of_kin_phone' => '+255760098765',
            ],
        ];

        $createdCount = 0;

        foreach ($patientsData as $index => $data) {
            // Check if patient with this national_id already exists for this tenant
            $existing = PatientModel::where('tenant_id', $tenantId)
                ->where('national_id', $data['national_id'])
                ->first();

            if ($existing) {
                continue;
            }

            $patientNumber = $mrnGenerator->nextForTenant($tenantId);

            $patient = new PatientModel();
            $patient->tenant_id = $tenantId;
            $patient->patient_number = $patientNumber;
            $patient->first_name = $data['first_name'];
            $patient->middle_name = $data['middle_name'];
            $patient->last_name = $data['last_name'];
            $patient->gender = $data['gender'];
            $patient->date_of_birth = $data['date_of_birth'];
            $patient->phone = $data['phone'];
            $patient->phone_normalized = $data['phone'];
            $patient->email = strtolower("{$data['first_name']}.{$data['last_name']}@example.com");
            $patient->national_id = $data['national_id'];
            $patient->country_code = 'TZ';
            $patient->region = 'Dar es Salaam';
            $patient->district = $data['district'];
            $patient->address_line = $data['address_line'];
            $patient->next_of_kin_name = $data['next_of_kin_name'];
            $patient->next_of_kin_phone = $data['next_of_kin_phone'];
            $patient->status = 'active';
            $patient->created_at = Carbon::now()->subDays(20 - $index)->subHours($index * 2);
            $patient->updated_at = $patient->created_at;
            $patient->save();

            $createdCount++;
        }

        $this->command?->info("Seeded {$createdCount} test patients for DSK Dispensary.");
    }
}
