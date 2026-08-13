<?php

namespace App\Modules\ServiceRequest\Application\Services;

/**
 * Resolved view of "which department(s) can this actor see/act on" for
 * Direct Service Queue V2 — see ServiceRequestDepartmentScopeResolver.
 * $departmentId is only meaningful when $canViewAllDepartments is false.
 */
final class ServiceRequestDepartmentScope
{
    public function __construct(
        public readonly bool $canViewAllDepartments,
        public readonly ?string $departmentId,
    ) {}

    /**
     * A department-scoped actor whose own staff profile has no department
     * assigned at all — distinct from "scoped to a real department." Callers
     * should treat this as "no access" (empty list, 403 on writes), not
     * "unrestricted."
     */
    public function hasNoAssignedDepartment(): bool
    {
        return ! $this->canViewAllDepartments && $this->departmentId === null;
    }
}
