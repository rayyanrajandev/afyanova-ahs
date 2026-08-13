<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC_Remediation_Plan.md Phase 6: the real, long-lived local database has
 * 275 permission rows; a fresh `php artisan migrate` on an empty database
 * only creates 121 of them. The other 162 are basic, obviously-legitimate
 * permissions (staff.read, medical.records.read, billing.invoices.read,
 * lab.order, and so on) referenced throughout routes/api.php, config/roles.php,
 * and dozens of FormRequest::authorize() methods — but no migration has ever
 * created their rows. The only explanation that fits: a comprehensive
 * permission-seeding seeder (almost certainly the RoleHierarchySeeder
 * deleted in an earlier phase of this remediation plan, given how closely
 * this list matches its ensurePermissionsExist() method) was run manually
 * against this database at some point in its history and never repeated
 * anywhere else — meaning any fresh environment (new deployment, CI, a
 * disaster-recovery restore from migrations alone) would have almost no
 * working permission catalog at all, regardless of anything else in this
 * remediation plan.
 *
 * This does not decide which role gets which permission — that is
 * config/roles.php's job, applied via `php artisan roles:sync`. This only
 * guarantees the permission rows exist so that job to actually work,
 * on this database and on every future one.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const PERMISSIONS = [
        'admissions.read',
        'admissions.view-audit-logs',
        'appointments.create',
        'appointments.manage-referrals',
        'appointments.read',
        'appointments.update',
        'appointments.update-status',
        'appointments.view-audit-logs',
        'appointments.view-referral-audit-logs',
        'billing.cash-accounts.manage',
        'billing.cash-accounts.read',
        'billing.consultation-mappings.manage',
        'billing.consultation-mappings.read',
        'billing.discounts.manage',
        'billing.discounts.read',
        'billing.financial-controls.read',
        'billing.invoices.cancel',
        'billing.invoices.create',
        'billing.invoices.issue',
        'billing.invoices.read',
        'billing.invoices.update-draft',
        'billing.invoices.void',
        'billing.payer-contracts.manage',
        'billing.payer-contracts.manage-authorization-rules',
        'billing.payer-contracts.manage-price-overrides',
        'billing.payer-contracts.read',
        'billing.payer-contracts.view-audit-logs',
        'billing.payer-contracts.view-authorization-audit-logs',
        'billing.payer-contracts.view-price-override-audit-logs',
        'billing.payments.record',
        'billing.payments.reverse',
        'billing.payments.view-history',
        'billing.refunds.approve',
        'billing.refunds.create',
        'billing.refunds.process',
        'billing.refunds.read',
        'billing.routing.read',
        'billing.service-catalog.manage',
        'claims.insurance.create',
        'claims.insurance.read',
        'claims.insurance.update',
        'claims.insurance.update-status',
        'claims.insurance.view-audit-logs',
        'departments.read',
        'emergency.triage.create',
        'emergency.triage.manage-transfers',
        'emergency.triage.read',
        'emergency.triage.update',
        'emergency.triage.update-status',
        'emergency.triage.view-audit-logs',
        'emergency.triage.view-transfer-audit-logs',
        'inpatient.ward.create-care-plan',
        'inpatient.ward.create-round-note',
        'inpatient.ward.create-task',
        'inpatient.ward.manage-discharge-checklist',
        'inpatient.ward.read',
        'inpatient.ward.update-care-plan',
        'inpatient.ward.update-care-plan-status',
        'inpatient.ward.update-task-status',
        'inpatient.ward.view-audit-logs',
        'lab.order',
        'lab.result.enter',
        'lab.result.release',
        'lab.result.verify',
        'lab.sample.collect',
        'lab.sample.reject',
        'lab.test.perform',
        'laboratory.orders.create',
        'laboratory.orders.read',
        'laboratory.orders.update-status',
        'laboratory.orders.verify-result',
        'medical.records.amend',
        'medical.records.archive',
        'medical.records.attest',
        'medical.records.create',
        'medical.records.finalize',
        'medical.records.read',
        'medical.records.update',
        'patient.vitals.record',
        'patients.view-audit-logs',
        'pharmacy.orders.create',
        'pharmacy.orders.manage-policy',
        'pharmacy.orders.read',
        'pharmacy.orders.reconcile',
        'pharmacy.orders.update-status',
        'pharmacy.orders.verify-dispense',
        'platform.cross-tenant.manage-audit-holds',
        'platform.cross-tenant.read',
        'platform.cross-tenant.view-audit-holds',
        'platform.cross-tenant.view-audit-logs',
        'platform.cross-tenant.write',
        'platform.facilities.create',
        'platform.facilities.manage-owners',
        'platform.facilities.update',
        'platform.facilities.update-status',
        'platform.feature-flag-overrides.manage',
        'platform.feature-flag-overrides.view-audit-logs',
        'platform.multi-facility.approve-acceptance',
        'platform.multi-facility.execute-rollback',
        'platform.multi-facility.manage-incidents',
        'platform.multi-facility.manage-rollouts',
        'platform.multi-facility.read',
        'platform.multi-facility.view-audit-logs',
        'platform.rbac.manage-roles',
        'platform.rbac.view-audit-logs',
        'platform.users.approval-cases.create',
        'platform.users.approval-cases.manage',
        'platform.users.approval-cases.read',
        'platform.users.approval-cases.review',
        'platform.users.approval-cases.view-audit-logs',
        'platform.users.manage-facilities',
        'pos.cafeteria.create',
        'pos.cafeteria.manage-catalog',
        'pos.cafeteria.read',
        'pos.frontdesk-quick.create',
        'pos.frontdesk-quick.read',
        'pos.lab-quick.create',
        'pos.lab-quick.read',
        'pos.pharmacy-otc.create',
        'pos.pharmacy-otc.read',
        'pos.registers.manage',
        'pos.registers.read',
        'pos.sales.create',
        'pos.sales.read',
        'pos.sales.refund',
        'pos.sales.void',
        'pos.sessions.manage',
        'pos.sessions.read',
        'radiology.orders.create',
        'radiology.orders.read',
        'radiology.orders.update',
        'radiology.orders.update-status',
        'radiology.orders.view-audit-logs',
        'specialties.read',
        'staff.clinical-directory.read',
        'staff.credentialing.manage-profile',
        'staff.credentialing.manage-registrations',
        'staff.credentialing.read',
        'staff.credentialing.verify',
        'staff.credentialing.view-audit-logs',
        'staff.documents.create',
        'staff.documents.read',
        'staff.documents.update',
        'staff.documents.update-status',
        'staff.documents.verify',
        'staff.documents.view-audit-logs',
        'staff.privileges.approve',
        'staff.privileges.create',
        'staff.privileges.read',
        'staff.privileges.review',
        'staff.privileges.update',
        'staff.privileges.update-status',
        'staff.privileges.view-audit-logs',
        'staff.read',
        'staff.specialties.read',
        'theatre.procedures.create',
        'theatre.procedures.manage-resources',
        'theatre.procedures.read',
        'theatre.procedures.update',
        'theatre.procedures.update-status',
        'theatre.procedures.view-audit-logs',
        'theatre.procedures.view-resource-audit-logs',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $existing = DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('name')
            ->all();
        $existingSet = array_flip($existing);

        $rows = [];
        foreach (self::PERMISSIONS as $name) {
            if (isset($existingSet[$name])) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('permissions')->insert($rows);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op. These permissions are checked live throughout
        // routes/api.php and multiple FormRequest::authorize() methods — removing
        // the rows would re-introduce the exact "checked but not seeded" bug
        // class this migration exists to fix, and roles:sync would then need to
        // be re-run to re-establish any role grants that depended on them.
    }
};
