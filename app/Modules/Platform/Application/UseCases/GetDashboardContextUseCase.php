<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Models\User;
use App\Modules\Platform\Application\Services\DashboardWorkflowRegistry;
use App\Modules\Platform\Application\Services\FacilitySubscriptionAccessService;
use App\Modules\Platform\Domain\ValueObjects\DashboardSessionContext;
use App\Support\Auth\EffectivePermissionNameResolver;
use Illuminate\Contracts\Auth\Authenticatable;

class GetDashboardContextUseCase
{
    public function __construct(
        private readonly DashboardWorkflowRegistry $workflowRegistry,
        private readonly EffectivePermissionNameResolver $permissionResolver,
        private readonly FacilitySubscriptionAccessService $subscriptionAccessService,
    ) {}

    /**
     * @return array{
     *     schemaVersion: string,
     *     defaultWorkflowKey: string,
     *     eligibleWorkflowKeys: array<int, string>,
     *     workflows: array<int, array{key: string, label: string, description: string, modules: array<int, string>, widgets: array<int, array{id: string, label: string, permission: string}>}>,
     *     canSwitchWorkflow: bool,
     *     session: array{roleCodes: array<int, string>, permissionCount: int}
     * }
     */
    public function execute(?Authenticatable $user): array
    {
        if (! $user instanceof User) {
            return $this->emptyContext();
        }

        $roleCodes = method_exists($user, 'roleCodes') ? $user->roleCodes() : [];
        $permissionNames = method_exists($user, 'permissionNames')
            ? $this->permissionResolver->resolve($user, $user->permissionNames())
            : [];

        $context = new DashboardSessionContext(
            roleCodesUpper: $roleCodes,
            permissionNames: $permissionNames,
            isFacilitySuperAdmin: method_exists($user, 'isFacilitySuperAdminAccess')
                ? (bool) $user->isFacilitySuperAdminAccess()
                : false,
            isPlatformSuperAdmin: method_exists($user, 'isPlatformSuperAdminAccess')
                ? (bool) $user->isPlatformSuperAdminAccess()
                : false,
        );

        $eligibleKeys = $this->workflowRegistry->eligibleWorkflowKeys($context);
        $subscriptionSummary = $this->subscriptionAccessService->currentAccessSummary();
        $grantedEntitlements = array_map(
            static fn (string $key): string => strtolower($key),
            array_values(array_filter(
                (array) ($subscriptionSummary['grantedEntitlements'] ?? []),
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            )),
        );
        $bypassSubscriptionFilter = $context->isFacilitySuperAdmin || $context->isPlatformSuperAdmin;
        $eligibleKeys = $this->workflowRegistry->filterWorkflowKeysByFacilitySubscription(
            $eligibleKeys,
            $grantedEntitlements,
            $bypassSubscriptionFilter,
        );
        if ($eligibleKeys === []) {
            $eligibleKeys = [DashboardWorkflowRegistry::WORKFLOW_FRONT_DESK];
        }
        $defaultKey = $eligibleKeys[0] ?? $this->workflowRegistry->defaultWorkflowKey($context);
        $workflows = array_values(array_filter(
            $this->workflowRegistry->eligibleWorkflowDefinitions($context),
            static fn (array $workflow): bool => in_array($workflow['key'], $eligibleKeys, true),
        ));

        $canSwitch = $context->isFacilitySuperAdmin
            || $context->isPlatformSuperAdmin
            || $context->matchesAnyRole([
                'PLATFORM.USER.ADMIN',
                'PLATFORM.RBAC.ADMIN',
                'PLATFORM.SUBSCRIPTION.ADMIN',
                'ADMIN.FACILITY',
            ])
            || count($eligibleKeys) > 1;

        return [
            'schemaVersion' => 'dashboard-context.v2',
            'defaultWorkflowKey' => $defaultKey,
            'eligibleWorkflowKeys' => $eligibleKeys,
            'workflows' => $workflows,
            'canSwitchWorkflow' => $canSwitch,
            'session' => [
                'roleCodes' => $roleCodes,
                'permissionCount' => count($permissionNames),
            ],
        ];
    }

    /**
     * @return array{
     *     schemaVersion: string,
     *     defaultWorkflowKey: string,
     *     eligibleWorkflowKeys: array<int, string>,
     *     workflows: array<int, array{key: string, label: string, description: string, modules: array<int, string>, widgets: array<int, array{id: string, label: string, permission: string}>}>,
     *     canSwitchWorkflow: bool,
     *     session: array{roleCodes: array<int, string>, permissionCount: int}
     * }
     */
    private function emptyContext(): array
    {
        return [
            'schemaVersion' => 'dashboard-context.v2',
            'defaultWorkflowKey' => DashboardWorkflowRegistry::WORKFLOW_FRONT_DESK,
            'eligibleWorkflowKeys' => [DashboardWorkflowRegistry::WORKFLOW_FRONT_DESK],
            'workflows' => array_values(array_filter(
                $this->workflowRegistry->workflowCatalog(),
                static fn (array $workflow): bool => $workflow['key'] === DashboardWorkflowRegistry::WORKFLOW_FRONT_DESK,
            )),
            'canSwitchWorkflow' => false,
            'session' => [
                'roleCodes' => [],
                'permissionCount' => 0,
            ],
        ];
    }
}
