<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        // Laboratory workflow
        'lab.order',
        'lab.sample.collect',
        'lab.sample.reject',
        'lab.test.perform',
        'lab.result.enter',
        'lab.result.verify',
        'lab.result.release',

        // Pharmacy workflow
        'medication.prescribe',
        'medication.dispense',
        'dispense.cancel',

        // Radiology / Imaging workflow
        'imaging.order',
        'imaging.perform',
        'imaging.result.enter',
        'imaging.result.verify',

        // Patient workflow
        'patient.demographics.update',
        'patient.allergies.manage',
        'patient.medications.manage',
        'patient.vitals.record',

        // Appointment workflow
        'appointment.reschedule',
        'appointment.cancel',
        'appointment.check-in',
        'appointment.check-out',

        // Staff workflow
        'staff.employment.update',
        'staff.status.update',
    ];

    /**
     * Maps old broad permission → [roleCode ⇒ [newGranularPermissions]].
     * Only roles that had the old permission get the new ones.
     */
    private function rolePermissionMap(): array
    {
        return [
            // ── Laboratory ──────────────────────────────────────
            'laboratory.orders.create' => [
                'CLINICAL.PHYSICIAN' => ['lab.order'],
                'CLINICAL.GENERAL' => ['lab.order'],
                'CLINICAL.EMERGENCY' => ['lab.order'],
                'LAB.STAFF' => ['lab.sample.collect', 'lab.test.perform', 'lab.result.enter'],
                'LAB.SUPERVISOR' => ['lab.sample.collect', 'lab.test.perform', 'lab.result.enter'],
                'LAB.MANAGER' => ['lab.sample.collect', 'lab.test.perform', 'lab.result.enter'],
            ],
            'laboratory.orders.update-status' => [
                'LAB.STAFF' => ['lab.sample.reject', 'lab.result.enter'],
                'LAB.SUPERVISOR' => ['lab.sample.reject', 'lab.result.enter', 'lab.result.verify', 'lab.result.release'],
                'LAB.MANAGER' => ['lab.sample.reject', 'lab.result.enter', 'lab.result.verify', 'lab.result.release'],
            ],
            'laboratory.orders.verify-result' => [
                'LAB.STAFF' => [],
                'LAB.SUPERVISOR' => ['lab.result.verify'],
                'LAB.MANAGER' => ['lab.result.verify'],
            ],

            // ── Pharmacy ────────────────────────────────────────
            'pharmacy.orders.create' => [
                'CLINICAL.PHYSICIAN' => ['medication.prescribe'],
                'CLINICAL.GENERAL' => ['medication.prescribe'],
                'CLINICAL.EMERGENCY' => ['medication.prescribe'],
                'PHARMACY.STAFF' => ['medication.dispense'],
                'PHARMACY.SUPERVISOR' => ['medication.dispense'],
                'PHARMACY.MANAGER' => ['medication.dispense'],
            ],
            'pharmacy.orders.update-status' => [
                'PHARMACY.STAFF' => ['medication.dispense'],
                'PHARMACY.SUPERVISOR' => ['medication.dispense', 'dispense.cancel'],
                'PHARMACY.MANAGER' => ['medication.dispense', 'dispense.cancel'],
            ],

            // ── Radiology ───────────────────────────────────────
            'radiology.orders.create' => [
                'CLINICAL.PHYSICIAN' => ['imaging.order'],
                'CLINICAL.GENERAL' => ['imaging.order'],
                'CLINICAL.EMERGENCY' => ['imaging.order'],
                'RADIOLOGY.STAFF' => ['imaging.perform'],
                'RADIOLOGY.SUPERVISOR' => ['imaging.perform'],
                'RADIOLOGY.MANAGER' => ['imaging.perform'],
            ],
            'radiology.orders.update-status' => [
                'RADIOLOGY.STAFF' => ['imaging.result.enter'],
                'RADIOLOGY.SUPERVISOR' => ['imaging.result.enter', 'imaging.result.verify'],
                'RADIOLOGY.MANAGER' => ['imaging.result.enter', 'imaging.result.verify'],
            ],

            // ── Patients ────────────────────────────────────────
            'patients.update' => [
                'ADMIN.FACILITY' => ['patient.demographics.update', 'patient.allergies.manage', 'patient.medications.manage', 'patient.vitals.record'],
                'ADMIN.REGISTRATION' => ['patient.demographics.update'],
                'CLINICAL.PHYSICIAN' => ['patient.demographics.update', 'patient.allergies.manage', 'patient.medications.manage', 'patient.vitals.record'],
                'CLINICAL.GENERAL' => ['patient.demographics.update', 'patient.medications.manage', 'patient.vitals.record'],
                'CLINICAL.EMERGENCY' => ['patient.demographics.update', 'patient.vitals.record'],
            ],

            // ── Appointments ────────────────────────────────────
            'appointments.update' => [
                'ADMIN.FACILITY' => ['appointment.reschedule', 'appointment.cancel'],
                'ADMIN.REGISTRATION' => ['appointment.reschedule', 'appointment.cancel'],
            ],
            'appointments.update-status' => [
                'ADMIN.REGISTRATION' => ['appointment.check-in', 'appointment.check-out'],
            ],

            // ── Staff ───────────────────────────────────────────
            'staff.update' => [
                'ADMIN.FACILITY' => ['staff.employment.update', 'staff.status.update'],
                'ADMIN.HR' => ['staff.employment.update', 'staff.status.update'],
            ],
        ];
    }

    public function up(): void
    {
        // Step 1: Insert all new permission rows
        $now = now();
        foreach (self::NEW_PERMISSIONS as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        // Step 2: Copy role assignments from old broad permissions to new granular ones
        foreach ($this->rolePermissionMap() as $sourcePermission => $roleMapping) {
            foreach ($roleMapping as $roleCode => $targetPermissions) {
                if (empty($targetPermissions)) {
                    continue;
                }

                $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
                if ($roleId === null) {
                    continue;
                }

                foreach ($targetPermissions as $targetPerm) {
                    $permId = DB::table('permissions')->where('name', $targetPerm)->value('id');
                    if ($permId === null) {
                        continue;
                    }

                    DB::table('permission_role')->updateOrInsert(
                        ['permission_id' => $permId, 'role_id' => $roleId],
                    );
                }
            }
        }
    }

    public function down(): void
    {
        // Collect all new permission IDs
        $newPermIds = DB::table('permissions')
            ->whereIn('name', self::NEW_PERMISSIONS)
            ->pluck('id');

        // Detach from all roles
        DB::table('permission_role')
            ->whereIn('permission_id', $newPermIds)
            ->delete();

        // Delete the permission rows
        DB::table('permissions')
            ->whereIn('name', self::NEW_PERMISSIONS)
            ->delete();
    }
};
