<?php

use App\Support\Rbac\PermissionUsageAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * RBAC_Remediation_Plan.md Phase 6.1/6.2: this is the CI tripwire. A future
 * PR that checks a permission name (via can: middleware, hasPermissionTo(),
 * ->authorize(), or ->can()) that was never seeded — whether a typo or a
 * permission nobody remembered to grant to any role — fails this test.
 *
 * First real run of this tool (2026-07-23) found and fixed 8 genuine bugs
 * beyond what the original manual audit caught: a permission naming-drift
 * bug affecting audit-log viewing across 4 modules (the same class of bug
 * as the already-known clinical_procedure.* vs clinical-procedure.* one),
 * a route checking an entirely wrong permission (patient-vitals store
 * checked inpatient.ward.create instead of patient.vitals.record), and 4
 * inventory-management permissions that were never seeded to any role.
 */
it('has no permission checked in code that is not seeded or an allowlisted Gate ability', function (): void {
    $report = app(PermissionUsageAuditor::class)->audit();

    expect($report['orphanedChecks'])
        ->toBe([], 'Orphaned permission check(s) found: '.implode(', ', $report['orphanedChecks'])
            .'. Each is checked somewhere in code (can: middleware, hasPermissionTo(), authorize(), or can()) '
            .'but does not exist as a seeded permission and is not on the Gate::define() allowlist in '
            .'AppServiceProvider — meaning only a universal-admin-bypass user could ever pass it. Either seed '
            .'the permission to the correct role(s) in config/roles.php (+ a migration if the permission row '
            .'does not exist yet — roles:sync only looks up existing permission ids, it does not create '
            .'missing ones), or fix the naming mismatch if this is a typo against an existing permission.');
});

it('actually detects an orphaned permission check written into a real file', function (): void {
    // Proves the regex-based file scan itself works, not just the diffing
    // logic — writes a real throwaway PHP file containing a permission
    // check that cannot possibly be seeded, runs the real auditor against
    // the real app/ tree (this file included), then deletes it.
    $fixturePath = app_path('Support/Rbac/__tripwire_fixture_do_not_commit.php');

    file_put_contents($fixturePath, <<<'PHP'
        <?php
        // Throwaway fixture for RbacPermissionUsageAuditTest — deleted immediately after use.
        function __tripwireFixtureCheck($user): bool
        {
            return $user->hasPermissionTo('this.permission.was.never.seeded.anywhere');
        }
        PHP);

    try {
        $report = app(PermissionUsageAuditor::class)->audit();

        expect($report['orphanedChecks'])->toContain('this.permission.was.never.seeded.anywhere');
    } finally {
        @unlink($fixturePath);
    }
});
