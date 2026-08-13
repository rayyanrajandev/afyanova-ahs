<?php

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\ValueObjects\DashboardSessionContext;

/**
 * Server source of truth for dashboard workflow landing (roles + effective permissions).
 *
 * Most metadata is now sourced from config/modules.php — the single point of registration.
 * Role-code constants and non-module permission logic remain here for historical compatibility.
 */
final class DashboardWorkflowRegistry
{
    public const WORKFLOW_ADMIN = 'admin';
    public const WORKFLOW_EMERGENCY = 'emergency';
    public const WORKFLOW_OPERATIONS = 'operations';
    public const WORKFLOW_CASHIER = 'cashier';
    public const WORKFLOW_CLINICIAN = 'clinician';
    public const WORKFLOW_RECORDS = 'records';
    public const WORKFLOW_NURSING = 'nursing';
    public const WORKFLOW_THEATRE = 'theatre';
    public const WORKFLOW_DIRECT_SERVICE = 'direct_service';
    public const WORKFLOW_SUPPLY = 'supply';
    public const WORKFLOW_FRONT_DESK = 'front_desk';

    public const PRIORITY_ORDER = [
        self::WORKFLOW_ADMIN,
        self::WORKFLOW_EMERGENCY,
        self::WORKFLOW_OPERATIONS,
        self::WORKFLOW_CASHIER,
        self::WORKFLOW_CLINICIAN,
        self::WORKFLOW_RECORDS,
        self::WORKFLOW_NURSING,
        self::WORKFLOW_THEATRE,
        self::WORKFLOW_DIRECT_SERVICE,
        self::WORKFLOW_SUPPLY,
        self::WORKFLOW_FRONT_DESK,
    ];

    /**
     * @var array<int, string>
     */
    private const PROCUREMENT_REQUISITION_ROLE_CODES = [
        'CLINICAL.GENERAL',
        'CLINICAL.PHYSICIAN',
        'CLINICAL.NURSE',
        'CLINICAL.EMERGENCY',
        'LAB.STAFF', 'LAB.SUPERVISOR', 'LAB.MANAGER',
        'PHARMACY.STAFF', 'PHARMACY.SUPERVISOR', 'PHARMACY.MANAGER',
        'RADIOLOGY.STAFF', 'RADIOLOGY.SUPERVISOR', 'RADIOLOGY.MANAGER',
        'THEATRE.STAFF', 'THEATRE.SUPERVISOR', 'THEATRE.MANAGER',
    ];

    public function __construct(
        private readonly ModuleRegistryService $moduleRegistry,
    ) {}

    /**
     * @return array<int, array{key: string, label: string, description: string, modules: array<int, string>, widgets: array<int, array{id: string, label: string, permission: string}>}>
     */
    public function workflowCatalog(): array
    {
        $catalog = [];
        $moduleWidgets = $this->moduleRegistry->buildWorkflowWidgets();

        foreach ($this->moduleRegistry->workflowDefinitions() as $key => $wf) {
            $widgets = array_merge((array) ($wf['widgets'] ?? []), $moduleWidgets[$key] ?? []);

            $catalog[] = [
                'key' => $key,
                'label' => (string) ($wf['label'] ?? ''),
                'description' => (string) ($wf['description'] ?? ''),
                'modules' => (array) ($wf['modules'] ?? []),
                'widgets' => $widgets,
            ];
        }

        return $catalog;
    }

    /**
     * @return array<int, string>
     */
    public function eligibleWorkflowKeys(DashboardSessionContext $context): array
    {
        $allow = [];

        foreach ($this->moduleRegistry->workflowDefinitions() as $key => $wf) {
            $roleCodes = (array) ($wf['role_codes'] ?? []);
            $evaluated = false;

            if ($key === self::WORKFLOW_ADMIN && ($context->isFacilitySuperAdmin || $context->isPlatformSuperAdmin)) {
                $allow[$key] = true;
                continue;
            }

            if ($context->matchesAnyRole($roleCodes)) {
                $allow[$key] = true;
                $evaluated = true;
            }

            if ($key === self::WORKFLOW_ADMIN && $context->matchesAnyRole([
                'PLATFORM.USER.ADMIN', 'PLATFORM.RBAC.ADMIN',
                'PLATFORM.SUBSCRIPTION.ADMIN', 'ADMIN.FACILITY',
            ])) {
                $allow[$key] = true;
            }

            // Permission-based eligibility for specific workflows
            if ($key === self::WORKFLOW_CASHIER) {
                if ($context->hasPermission('billing.invoices.read') || $context->hasPermission('claims.insurance.read')) {
                    $allow[$key] = true;
                }
            }

            if ($key === self::WORKFLOW_CLINICIAN && ! ($allow[$key] ?? false)) {
                $isNurse = $context->matchesAnyRole(['CLINICAL.NURSE']);
                $isRecords = $context->matchesAnyRole(['ADMIN.MEDICAL.RECORDS']);
                if ($context->hasPermission('medical.records.read') && ! $isNurse && ! $isRecords) {
                    $allow[$key] = true;
                }
            }

            if ($key === self::WORKFLOW_NURSING && ! ($allow[$key] ?? false)) {
                $isEmerg = $context->matchesAnyRole(['CLINICAL.EMERGENCY']);
                if ($context->hasPermission('inpatient.ward.read') && ! $isEmerg) {
                    $allow[$key] = true;
                }
            }

            if ($key === self::WORKFLOW_DIRECT_SERVICE && ! $evaluated) {
                if (
                    $context->hasPermission('laboratory.orders.read')
                    || $context->hasPermission('pharmacy.orders.read')
                    || $context->hasPermission('radiology.orders.read')
                    || $context->hasPermission('clinical_procedure.orders.read')
                ) {
                    $allow[$key] = true;
                }
            }

            if ($key === self::WORKFLOW_FRONT_DESK && ! ($allow[$key] ?? false)) {
                if (
                    $context->hasPermission('patients.read')
                    && $context->hasPermission('appointments.read')
                ) {
                    $isClinician = $context->matchesAnyRole(['CLINICAL.PHYSICIAN', 'CLINICAL.GENERAL']);
                    $isNurse = $context->matchesAnyRole(['CLINICAL.NURSE']);
                    $isRecords = $context->matchesAnyRole(['ADMIN.MEDICAL.RECORDS']);
                    $hasMedicalRecordsRead = $context->hasPermission('medical.records.read');
                    $isHoldingClinicianHat = $isClinician || ($hasMedicalRecordsRead && ! $isNurse && ! $isRecords);
                    if (! $isHoldingClinicianHat) {
                        $allow[$key] = true;
                    }
                }
            }
        }

        // Operations, records, supply, theatre: legacy permission-based checks
        if ($this->isOperationsEligible($context)) {
            $allow[self::WORKFLOW_OPERATIONS] = true;
        }

        if ($this->isRecordsEligible($context)) {
            $allow[self::WORKFLOW_RECORDS] = true;
        }

        if ($this->isSupplyEligible($context)) {
            $allow[self::WORKFLOW_SUPPLY] = true;
        }

        if ($this->isTheatreEligible($context)) {
            $allow[self::WORKFLOW_THEATRE] = true;
        }

        // Front desk fallback for registration role
        if ($context->matchesAnyRole(['ADMIN.REGISTRATION'])) {
            $allow[self::WORKFLOW_FRONT_DESK] = true;
        }

        $ordered = [];
        foreach (self::PRIORITY_ORDER as $key) {
            if (isset($allow[$key])) {
                $ordered[] = $key;
            }
        }

        return $ordered !== [] ? $ordered : [self::WORKFLOW_FRONT_DESK];
    }

    public function defaultWorkflowKey(DashboardSessionContext $context): string
    {
        $ordered = $this->eligibleWorkflowKeys($context);

        return $ordered[0] ?? self::WORKFLOW_FRONT_DESK;
    }

    /**
     * @param  array<int, string>  $workflowKeys
     * @param  array<int, string>  $grantedEntitlementsLower
     * @return array<int, string>
     */
    public function filterWorkflowKeysByFacilitySubscription(
        array $workflowKeys,
        array $grantedEntitlementsLower,
        bool $bypassFilter,
    ): array {
        if ($bypassFilter || $grantedEntitlementsLower === []) {
            return $workflowKeys;
        }

        $granted = array_flip($grantedEntitlementsLower);

        return array_values(array_filter(
            $workflowKeys,
            fn (string $key): bool => $this->workflowPassesFacilityEntitlements($key, $granted),
        ));
    }

    /**
     * @return array<int, array{key: string, label: string, description: string, modules: array<int, string>, widgets: array<int, array{id: string, label: string, permission: string}>}>
     */
    public function eligibleWorkflowDefinitions(DashboardSessionContext $context): array
    {
        $eligible = array_flip($this->eligibleWorkflowKeys($context));
        $definitions = [];

        foreach ($this->workflowCatalog() as $workflow) {
            if (! isset($eligible[$workflow['key']])) {
                continue;
            }

            $widgets = array_values(array_filter(
                $workflow['widgets'] ?? [],
                fn (array $widget): bool => $context->hasPermission((string) ($widget['permission'] ?? '')),
            ));

            $definitions[] = $this->presentWorkflowDefinition($workflow, $context, $widgets);
        }

        return $definitions;
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @param  array<int, array{id: string, label: string, permission: string}>  $widgets
     * @return array{key: string, label: string, description: string, modules: array<int, string>, widgets: array<int, array{id: string, label: string, permission: string}>}
     */
    private function presentWorkflowDefinition(array $workflow, DashboardSessionContext $context, array $widgets): array
    {
        $label = (string) $workflow['label'];
        $description = (string) $workflow['description'];

        if ($workflow['key'] === self::WORKFLOW_DIRECT_SERVICE) {
            $presentation = $this->resolveDirectServicePresentation($context);
            $label = $presentation['label'];
            $description = $presentation['description'];
        }

        return [
            'key' => (string) $workflow['key'],
            'label' => $label,
            'description' => $description,
            'modules' => $workflow['modules'],
            'widgets' => $widgets,
        ];
    }

    /**
     * When the session maps to a single direct-service department, use that label in the UI
     * (e.g. Laboratory User sees "Laboratory", not generic "Direct Service").
     *
     * @return array{label: string, description: string}
     */
    /**
     * @return array{label: string, description: string}
     */
    private function resolveDirectServicePresentation(DashboardSessionContext $context): array
    {
        $wf = $this->moduleRegistry->workflow(self::WORKFLOW_DIRECT_SERVICE);
        $defaultLabel = (string) ($wf['label'] ?? 'Direct Service');
        $defaultDescription = (string) ($wf['description'] ?? '');

        // Single-role override
        $roleCodes = (array) ($wf['role_codes'] ?? []);
        $roleLabels = (array) ($wf['role_labels'] ?? []);
        $heldRoles = array_values(array_filter(
            $roleCodes,
            fn (string $roleCode): bool => $context->matchesAnyRole([$roleCode]),
        ));

        if (count($heldRoles) === 1 && isset($roleLabels[$heldRoles[0]])) {
            return $roleLabels[$heldRoles[0]];
        }

        // Single-permission override
        $permissionLabels = (array) ($wf['permission_labels'] ?? []);
        $heldPermissions = array_values(array_filter(
            array_keys($permissionLabels),
            fn (string $perm): bool => $context->hasPermission($perm),
        ));

        if (count($heldPermissions) === 1 && isset($permissionLabels[$heldPermissions[0]])) {
            return $permissionLabels[$heldPermissions[0]];
        }

        return [
            'label' => $defaultLabel,
            'description' => $defaultDescription,
        ];
    }

    /**
     * @param  array<string, bool>  $grantedEntitlementsLower
     */
    private function workflowPassesFacilityEntitlements(string $workflowKey, array $grantedEntitlementsLower): bool
    {
        if ($workflowKey === self::WORKFLOW_ADMIN) {
            return true;
        }

        $wf = $this->moduleRegistry->workflow($workflowKey);

        if ($wf === null) {
            return true;
        }

        $rule = $wf['facility_entitlements'] ?? null;

        if ($rule === null) {
            return true;
        }

        if (isset($rule['all'])) {
            foreach ((array) $rule['all'] as $entitlement) {
                if (! isset($grantedEntitlementsLower[strtolower((string) $entitlement)])) {
                    return false;
                }
            }

            return true;
        }

        if (isset($rule['any'])) {
            foreach ((array) $rule['any'] as $entitlement) {
                if (isset($grantedEntitlementsLower[strtolower((string) $entitlement)])) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function isOperationsEligible(DashboardSessionContext $context): bool
    {
        if ($context->matchesAnyRole(['ADMIN.HR'])) {
            return true;
        }

        if (! $context->hasPermission('staff.read')) {
            return false;
        }

        return $context->hasPermission('staff.credentialing.read')
            || $context->hasPermission('staff.privileges.read');
    }

    private function isRecordsEligible(DashboardSessionContext $context): bool
    {
        if ($context->matchesAnyRole(['ADMIN.MEDICAL.RECORDS'])) {
            return true;
        }

        if (! $context->hasPermission('medical.records.read')) {
            return false;
        }

        $isNurse = $context->matchesAnyRole(['CLINICAL.NURSE']);
        $isEmerg = $context->matchesAnyRole(['CLINICAL.EMERGENCY']);
        $isClinician = $context->matchesAnyRole(['CLINICAL.PHYSICIAN', 'CLINICAL.GENERAL']);

        if ($isNurse || $isEmerg || $isClinician) {
            return false;
        }

        return true;
    }

    private function isSupplyEligible(DashboardSessionContext $context): bool
    {
        if ($context->matchesAnyRole(['INVENTORY.STAFF', 'INVENTORY.SUPERVISOR', 'INVENTORY.MANAGER'])) {
            return true;
        }

        if ($context->matchesAnyRole(self::PROCUREMENT_REQUISITION_ROLE_CODES)) {
            return false;
        }

        return $context->hasPermission('inventory.procurement.read');
    }

    private function isTheatreEligible(DashboardSessionContext $context): bool
    {
        if ($context->matchesAnyRole(['THEATRE.STAFF', 'THEATRE.SUPERVISOR', 'THEATRE.MANAGER'])) {
            return true;
        }

        return $context->hasPermission('theatre.procedures.read');
    }
}
