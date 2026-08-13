<?php

namespace Database\Seeders;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use Illuminate\Database\Seeder;

class DskDepartmentsSeeder extends Seeder
{
    public function run(): void
    {
        $facility = FacilityModel::where('code', 'DSK')->first();

        if (!$facility) {
            $this->command?->error('DSK facility not found. Run InitialFacilitySeeder first.');
            return;
        }

        $departments = [
            [
                'code' => 'ADM',
                'name' => 'Administration Department',
                'service_type' => 'Administrative',
                'description' => 'Oversees facility operations, governance, human resources, and strategic coordination at DSK Dispensary. Manages staff scheduling, regulatory compliance, reporting, and day-to-day administrative leadership to ensure smooth facility running.',
                'is_patient_facing' => false,
                'is_appointmentable' => false,
            ],
            [
                'code' => 'OPD',
                'name' => 'Outpatient Department (OPD)',
                'service_type' => 'Clinical',
                'description' => 'Primary entry point for walk-in patients seeking medical consultation at DSK Dispensary. Handles triage, general outpatient consultations, minor treatments, referral coordination, and follow-up care for patients not requiring admission.',
                'is_patient_facing' => true,
                'is_appointmentable' => true,
            ],
            [
                'code' => 'NRS',
                'name' => 'Nursing Department',
                'service_type' => 'Clinical',
                'description' => 'Delivers nursing care across all service points at DSK Dispensary, including patient assessment, medication administration, wound care, vital signs monitoring, health education, and infection prevention and control (IPC) practices.',
                'is_patient_facing' => true,
                'is_appointmentable' => false,
            ],
            [
                'code' => 'PHA',
                'name' => 'Pharmacy Department',
                'service_type' => 'Pharmacy',
                'description' => 'Manages the procurement, storage, compounding, and dispensing of medicines and pharmaceutical supplies at DSK Dispensary. Provides patient counselling on proper medicine use, dosage, and potential side effects, and maintains accurate drug inventory records.',
                'is_patient_facing' => true,
                'is_appointmentable' => false,
            ],
            [
                'code' => 'LAB',
                'name' => 'Laboratory Department',
                'service_type' => 'Diagnostic',
                'description' => 'Performs essential diagnostic investigations including malaria smear, urinalysis, stool analysis, HIV rapid testing, blood grouping, pregnancy testing, and basic haematology and chemistry tests to support clinical decision-making at DSK Dispensary.',
                'is_patient_facing' => true,
                'is_appointmentable' => false,
            ],
            [
                'code' => 'RCH',
                'name' => 'Reproductive and Child Health (RCH/MCH) Department',
                'service_type' => 'Clinical',
                'description' => 'Provides comprehensive reproductive, maternal, newborn, and child health services including antenatal care (ANC), postnatal care (PNC), family planning, immunisations under the Expanded Programme on Immunisation (EPI), growth monitoring, and nutrition counselling for mothers and children at DSK Dispensary.',
                'is_patient_facing' => true,
                'is_appointmentable' => true,
            ],
            [
                'code' => 'MRO',
                'name' => 'Health Records Department',
                'service_type' => 'Administrative',
                'description' => 'Responsible for creating, maintaining, retrieving, and storing patient medical records at DSK Dispensary. Ensures confidentiality, accuracy, and timely availability of records during consultations, and manages data entry, filing, and reporting for DHIS2 and other health information systems.',
                'is_patient_facing' => true,
                'is_appointmentable' => false,
            ],
            [
                'code' => 'STR',
                'name' => 'Medical Supplies & Stores Department',
                'service_type' => 'Support',
                'description' => 'Handles the receipt, storage, issuance, and inventory control of medical supplies, equipment, instruments, and consumables at DSK Dispensary. Ensures stock availability, proper storage conditions, timely reordering, and accurate stock ledger management to prevent stock-outs and expiry.',
                'is_patient_facing' => false,
                'is_appointmentable' => false,
            ],
            [
                'code' => 'FIN',
                'name' => 'Finance / Cashier Department',
                'service_type' => 'Administrative',
                'description' => 'Manages patient billing, fee collection, receipting, cash management, and financial record-keeping at DSK Dispensary. Handles NHIF and other health insurance verification, claims processing, revenue reconciliation, and daily financial reporting to support facility financial sustainability.',
                'is_patient_facing' => true,
                'is_appointmentable' => false,
            ],
            [
                'code' => 'SPT',
                'name' => 'Support Services Department',
                'service_type' => 'Support',
                'description' => 'Provides essential non-clinical support including cleaning and environmental hygiene, security services, minor facility maintenance, waste management, laundry, and utility oversight to maintain a safe, clean, and functional environment at DSK Dispensary.',
                'is_patient_facing' => false,
                'is_appointmentable' => false,
            ],
        ];

        foreach ($departments as $dept) {
            DepartmentModel::firstOrCreate(
                [
                    'facility_id' => $facility->id,
                    'code' => $dept['code'],
                ],
                [
                    'tenant_id' => $facility->tenant_id,
                    'name' => $dept['name'],
                    'service_type' => $dept['service_type'],
                    'description' => $dept['description'],
                    'is_patient_facing' => $dept['is_patient_facing'],
                    'is_appointmentable' => $dept['is_appointmentable'],
                    'status' => 'active',
                ],
            );
        }

        $this->command?->info('Seeded ' . count($departments) . ' departments for DSK Dispensary.');
    }
}
