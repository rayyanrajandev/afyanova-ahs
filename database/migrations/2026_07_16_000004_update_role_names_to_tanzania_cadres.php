<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roles = config('roles');

        $collected = [];
        foreach ($roles as $roleDef) {
            $code = $roleDef['code'] ?? null;
            $name = $roleDef['name'] ?? null;
            if ($code === null || $name === null) {
                continue;
            }
            $collected[$code] = $name;
        }

        $nameUpdates = $collected + [
            'ADMIN.FACILITY'          => 'Hospital Administrator',
            'ADMIN.HR'                => 'Human Resources Officer',
            'ADMIN.MEDICAL.RECORDS'   => 'Health Records Officer-in-Charge',
            'ADMIN.REGISTRATION'      => 'Health Records Officer',
            'CLINICAL.GENERAL'        => 'Clinical Officer',
            'CLINICAL.PHYSICIAN'      => 'Medical Officer',
            'CLINICAL.NURSE'          => 'Nurse Officer',
            'CLINICAL.EMERGENCY'      => 'Casualty Nurse',
            'FINANCE.CASHIER'         => 'Cashier',
            'FINANCE.OFFICER'         => 'Accountant',
            'FINANCE.CONTROLLER'      => 'Finance Manager',
            'FINANCE.CLAIMS'          => 'Insurance Claims Officer',
            'LAB.STAFF'               => 'Laboratory Technologist',
            'LAB.SUPERVISOR'          => 'Chief Laboratory Technologist',
            'LAB.MANAGER'             => 'Laboratory Manager',
            'RADIOLOGY.STAFF'         => 'Radiographer',
            'RADIOLOGY.SUPERVISOR'    => 'Senior Radiographer',
            'RADIOLOGY.MANAGER'       => 'Radiology Manager',
            'PHARMACY.STAFF'          => 'Dispenser',
            'PHARMACY.SUPERVISOR'     => 'Pharmacist-in-Charge',
            'PHARMACY.MANAGER'        => 'Chief Pharmacist',
            'THEATRE.STAFF'           => 'Theatre Nurse',
            'THEATRE.SUPERVISOR'      => 'Theatre Nurse-in-Charge',
            'THEATRE.MANAGER'         => 'Theatre Manager',
            'INVENTORY.STAFF'         => 'Storekeeper',
            'INVENTORY.SUPERVISOR'    => 'Senior Storekeeper',
            'INVENTORY.MANAGER'       => 'Procurement Officer',
        ];

        foreach ($nameUpdates as $code => $name) {
            DB::table('roles')->where('code', $code)->update(['name' => $name]);
        }
    }

    public function down(): void
    {
        // Original pre-Tanzania display names
        $originalNames = [
            'ADMIN.FACILITY'          => 'Facility Admin',
            'ADMIN.HR'                => 'HR Admin',
            'ADMIN.MEDICAL.RECORDS'   => 'Medical Records Admin',
            'ADMIN.REGISTRATION'      => 'Registration Admin',
            'CLINICAL.GENERAL'        => 'Clinical General',
            'CLINICAL.PHYSICIAN'      => 'Clinical Physician',
            'CLINICAL.NURSE'          => 'Clinical Nurse',
            'CLINICAL.EMERGENCY'      => 'Clinical Emergency',
            'FINANCE.CASHIER'         => 'Finance Cashier',
            'FINANCE.OFFICER'         => 'Finance Officer',
            'FINANCE.CONTROLLER'      => 'Finance Controller',
            'FINANCE.CLAIMS'          => 'Finance Claims',
            'LAB.STAFF'               => 'Lab Staff',
            'LAB.SUPERVISOR'          => 'Lab Supervisor',
            'LAB.MANAGER'             => 'Lab Manager',
            'RADIOLOGY.STAFF'         => 'Radiology Staff',
            'RADIOLOGY.SUPERVISOR'    => 'Radiology Supervisor',
            'RADIOLOGY.MANAGER'       => 'Radiology Manager',
            'PHARMACY.STAFF'          => 'Pharmacy Staff',
            'PHARMACY.SUPERVISOR'     => 'Pharmacy Supervisor',
            'PHARMACY.MANAGER'        => 'Pharmacy Manager',
            'THEATRE.STAFF'           => 'Theatre Staff',
            'THEATRE.SUPERVISOR'      => 'Theatre Supervisor',
            'THEATRE.MANAGER'         => 'Theatre Manager',
            'INVENTORY.STAFF'         => 'Inventory Staff',
            'INVENTORY.SUPERVISOR'    => 'Inventory Supervisor',
            'INVENTORY.MANAGER'       => 'Inventory Manager',
        ];

        foreach ($originalNames as $code => $name) {
            DB::table('roles')->where('code', $code)->update(['name' => $name]);
        }
    }
};
