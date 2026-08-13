<?php

namespace App\Modules\ServiceRequest\Application\Services;

use App\Models\User;

/**
 * Direct Service Queue V2 ships with hard department enforcement, not just a
 * filter — see reports/patient-flow-redesign plan. No per-department RBAC
 * existed anywhere in this codebase before this: service.requests.*
 * permissions are granted per service-type role tier (HOSPITAL.LABORATORY.
 * USER etc.), not per specific department, so a lab tech in Department A
 * could see/act on Department B's tickets. This resolver is the single
 * place that decides an actor's enforced scope, reused by
 * ListServiceRequestsUseCase, ListServiceRequestStatusCountsUseCase, and
 * UpdateServiceRequestStatusUseCase so the three call sites can't drift.
 */
class ServiceRequestDepartmentScopeResolver
{
    public function resolve(?User $user): ServiceRequestDepartmentScope
    {
        if ($user === null) {
            return new ServiceRequestDepartmentScope(canViewAllDepartments: false, departmentId: null);
        }

        if ($user->can('service.requests.view-all-departments')) {
            return new ServiceRequestDepartmentScope(canViewAllDepartments: true, departmentId: null);
        }

        $departmentId = $user->staffProfile?->department_id;

        return new ServiceRequestDepartmentScope(
            canViewAllDepartments: false,
            departmentId: is_string($departmentId) && $departmentId !== '' ? $departmentId : null,
        );
    }
}
