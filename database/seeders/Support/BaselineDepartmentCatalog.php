<?php

namespace Database\Seeders\Support;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;

final class BaselineDepartmentCatalog
{
    /**
     * @return array<int, array{code:string,name:string,service_type:string,description:string}>
     */
    public static function definitions(): array
    {
        return [
            [
                'code' => 'OPD',
                'name' => 'General OPD',
                'service_type' => 'Clinical',
                'description' => 'General outpatient consultations and walk-in triage follow-up.',
            ],
            [
                'code' => 'ANC',
                'name' => 'Antenatal Clinic',
                'service_type' => 'Clinical',
                'description' => 'Routine and follow-up antenatal care visits.',
            ],
            [
                'code' => 'PED',
                'name' => 'Pediatrics Clinic',
                'service_type' => 'Clinical',
                'description' => 'Children outpatient consultations and reviews.',
            ],
            [
                'code' => 'DENT',
                'name' => 'Dental Clinic',
                'service_type' => 'Clinical',
                'description' => 'Dental consultations and follow-up care.',
            ],
            [
                'code' => 'EMR',
                'name' => 'Emergency Unit',
                'service_type' => 'Clinical',
                'description' => 'Emergency assessment, stabilization, and rapid response workflow.',
            ],
            [
                'code' => 'SURG',
                'name' => 'Surgery',
                'service_type' => 'Clinical',
                'description' => 'General surgical service line and perioperative coordination.',
            ],
            [
                'code' => 'THR',
                'name' => 'Theatre',
                'service_type' => 'Clinical',
                'description' => 'Operating theatre and perioperative procedure area.',
            ],
            [
                'code' => 'REC',
                'name' => 'Theatre Recovery',
                'service_type' => 'Clinical',
                'description' => 'Immediate post-procedure recovery and handoff observation area.',
            ],
            [
                'code' => 'MPR',
                'name' => 'Minor Procedures',
                'service_type' => 'Clinical',
                'description' => 'Minor procedures, wound care, and low-complexity intervention room.',
            ],
            [
                'code' => 'DRS',
                'name' => 'Dressing Room',
                'service_type' => 'Clinical',
                'description' => 'Dressing changes, wound review, and procedure follow-up support.',
            ],
            [
                'code' => 'WARD',
                'name' => 'Inpatient Ward',
                'service_type' => 'Clinical',
                'description' => 'General inpatient ward care and nursing coverage.',
            ],
            [
                'code' => 'MAT',
                'name' => 'Maternity Ward',
                'service_type' => 'Clinical',
                'description' => 'Maternity inpatient care, labour support, and postnatal follow-up.',
            ],
            [
                'code' => 'LAB',
                'name' => 'Laboratory',
                'service_type' => 'Diagnostic',
                'description' => 'Diagnostic specimen workflow, testing, and laboratory reporting.',
            ],
            [
                'code' => 'RAD',
                'name' => 'Radiology',
                'service_type' => 'Diagnostic',
                'description' => 'Diagnostic imaging operations and imaging result workflow.',
            ],
            [
                'code' => 'PHA',
                'name' => 'Pharmacy',
                'service_type' => 'Pharmacy',
                'description' => 'Medication preparation, dispensing, and pharmacy operations.',
            ],
            [
                'code' => 'ADM',
                'name' => 'Administration',
                'service_type' => 'Administrative',
                'description' => 'General facility administration, coordination, and executive office work.',
            ],
            [
                'code' => 'HR',
                'name' => 'Human Resources',
                'service_type' => 'Administrative',
                'description' => 'Staffing, human resources, workforce records, and staff support.',
            ],
            [
                'code' => 'MRO',
                'name' => 'Medical Records',
                'service_type' => 'Administrative',
                'description' => 'Records management, chart retrieval, and documentation control.',
            ],
            [
                'code' => 'CRE',
                'name' => 'Staff Credentialing',
                'service_type' => 'Administrative',
                'description' => 'Credentialing, registration verification, and privileging administration.',
            ],
            [
                'code' => 'FIN',
                'name' => 'Billing and Finance',
                'service_type' => 'Administrative',
                'description' => 'Billing, cashiering, claims, and finance office operations.',
            ],
            [
                'code' => 'FDS',
                'name' => 'Front Desk',
                'service_type' => 'Support',
                'description' => 'Registration, reception, queue support, and patient-facing front desk work.',
            ],
            [
                'code' => 'STR',
                'name' => 'Stores and Inventory',
                'service_type' => 'Support',
                'description' => 'Stock receipt, issue, storage, and inventory movement operations.',
            ],
            [
                'code' => 'ICT',
                'name' => 'ICT and Systems',
                'service_type' => 'Support',
                'description' => 'ICT support, user access administration, and systems operations.',
            ],
            [
                'code' => 'MNT',
                'name' => 'Maintenance and Utilities',
                'service_type' => 'Support',
                'description' => 'Facility maintenance, utilities, and environmental systems support.',
            ],
            [
                'code' => 'HSK',
                'name' => 'Housekeeping and Environmental Services',
                'service_type' => 'Support',
                'description' => 'Cleaning, sanitation, and environmental hygiene support.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function seedForScope(?string $tenantId, ?string $facilityId): array
    {
        $departmentNamesByCode = [];

        foreach (self::definitions() as $seed) {
            $department = DepartmentModel::query()->firstOrNew([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'code' => $seed['code'],
            ]);

            $department->fill([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'code' => $seed['code'],
                'name' => $seed['name'],
                'service_type' => $seed['service_type'],
                'is_patient_facing' => self::isPatientFacing($seed['code'], $seed['service_type']),
                'is_appointmentable' => self::isAppointmentable($seed['code']),
                'manager_user_id' => null,
                'status' => 'active',
                'status_reason' => null,
                'description' => $seed['description'],
            ]);
            $department->save();

            $departmentNamesByCode[$seed['code']] = $department->name;
        }

        return $departmentNamesByCode;
    }

    private static function isPatientFacing(string $code, string $serviceType): bool
    {
        $normalizedCode = strtoupper(trim($code));
        $normalizedServiceType = strtolower(trim($serviceType));

        return in_array($normalizedCode, [
            'OPD', 'ANC', 'PED', 'DENT', 'EMR', 'SURG', 'THR', 'REC', 'MPR', 'DRS',
            'WARD', 'MAT', 'LAB', 'RAD', 'PHA', 'MRO', 'FIN', 'FDS',
        ], true) || in_array($normalizedServiceType, ['clinical', 'diagnostic', 'pharmacy'], true);
    }

    private static function isAppointmentable(string $code): bool
    {
        return in_array(strtoupper(trim($code)), [
            'OPD', 'ANC', 'PED', 'DENT', 'SURG', 'MPR', 'DRS', 'LAB', 'RAD',
        ], true);
    }
}
