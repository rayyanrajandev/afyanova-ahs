<?php

namespace App\Http\Middleware;

use App\Modules\Platform\Application\Services\FacilitySubscriptionAccessService;
use App\Modules\Platform\Application\Services\ModuleRegistryService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMappedFacilitySubscriptionEntitlement
{
    /**
     * Non-module route overrides that don't fit the module registry pattern.
     *
     * @var array<string, array<int, string>>
     */
    private const SPECIAL_ENTITLEMENT_MAP = [
        'appointments.start-consultation' => ['appointments.provider_sessions'],
        'appointments.manage-provider-session' => ['appointments.provider_sessions'],
        'appointments.referrals.' => ['appointments.referrals'],
        'appointments.' => ['appointments.scheduling'],

        // Aggregate / reference admission signals for front-office plans (e.g. Clinic Starter) that have
        // scheduling but not the full admissions.management SKU; admissions.read remains enforced per route.
        'admissions.status-counts' => ['appointments.scheduling'],
        'admissions.discharge-destination-options' => ['appointments.scheduling'],
        'admissions.index' => ['appointments.scheduling'],
        'admissions.' => ['admissions.management'],

        'medical-records.signer-attestations.' => ['medical_records.governance'],
        'medical-records.versions.' => ['medical_records.governance'],
        'medical-records.audit-logs.' => ['medical_records.governance'],
        'medical-records.audit-logs' => ['medical_records.governance'],
        'medical-records.' => ['medical_records.core'],

        'encounters.audit-logs.' => ['medical_records.governance'],
        'encounters.audit-logs' => ['medical_records.governance'],
        'encounters.' => ['medical_records.core'],

        'emergency-triage-cases.' => ['emergency.triage'],
        'service-requests.' => ['clinical.walk_in_queue'],

        'inpatient-ward.tasks.' => ['inpatient.tasks'],
        'inpatient-ward.round-notes.' => ['inpatient.tasks'],
        'inpatient-ward.care-plans.' => ['inpatient.care_plans'],
        'inpatient-ward.discharge-checklists.' => ['inpatient.care_plans'],
        'inpatient-ward.task-status-counts' => ['appointments.scheduling'],
        'inpatient-ward.care-plan-status-counts' => ['appointments.scheduling'],
        'inpatient-ward.discharge-checklist-status-counts' => ['appointments.scheduling'],

        'inventory-procurement.suppliers.' => ['inventory.suppliers'],
        'inventory-procurement.warehouses.' => ['inventory.warehouses'],
        'inventory-procurement.warehouse-transfers.' => ['inventory.transfers'],
        'inventory-procurement.analytics.' => ['inventory.analytics'],
        'inventory-procurement.stock-movements.' => ['inventory.stock_movements'],
        'inventory-procurement.department-requisitions.' => ['inventory.requisitions'],
        'inventory-procurement.department-stock.' => ['inventory.stock_issue'],
        'inventory-procurement.shortage-queue.' => ['inventory.stock_issue'],
        'inventory-procurement.procurement-requests.' => ['inventory.procurement'],
        'inventory-procurement.msd-orders.' => ['inventory.procurement'],
        'inventory-procurement.supplier-lead-times.' => ['inventory.procurement'],
        'inventory-procurement.items.' => ['inventory.items'],
        'inventory-procurement.batches.' => ['inventory.items'],
        'inventory-procurement.reference-data.' => ['inventory.items'],
        'inventory-procurement.barcode-lookup' => ['inventory.items'],
        'inventory-procurement.' => ['inventory.procurement'],

        'staff.credentialing.' => ['staff.credentialing'],
        'staff.credentialing-alerts' => ['staff.credentialing'],
        'staff.documents.' => ['staff.documents'],
        'staff.privileges.' => ['staff.privileges'],
        'staff.clinical-directory.' => ['staff.directory'],
        'staff.specialties.' => ['clinical.specialties'],
        'staff.' => ['staff.profiles'],
        'privilege-catalogs.' => ['staff.privileges'],
        'specialties.' => ['clinical.specialties'],
        'departments.' => ['departments.management'],
    ];

    /**
     * @var array<string, array<int, string>>|null
     */
    private ?array $mergedEntitlementMap = null;

    public function __construct(
        private readonly FacilitySubscriptionAccessService $subscriptionAccessService,
        private readonly ModuleRegistryService $moduleRegistry,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    private function entitlementMap(): array
    {
        if ($this->mergedEntitlementMap === null) {
            /**
             * The 2027 plan catalog (config/plan_catalog.php 'route_entitlements')
             * is the single source of truth. When it is non-empty the legacy
             * hardcoded SPECIAL_ENTITLEMENT_MAP is intentionally unused; the
             * module registry is still merged so modules can add their own
             * route-prefix mappings without editing this middleware.
             */
            $configMap = (array) config('plan_catalog.route_entitlements', []);

            $this->mergedEntitlementMap = [
                ...($configMap !== [] ? $configMap : self::SPECIAL_ENTITLEMENT_MAP),
                ...$this->moduleRegistry->buildRouteEntitlementMap(),
            ];
        }

        return $this->mergedEntitlementMap;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $entitlements = $this->entitlementsForRequest($request);

        if ($entitlements === []) {
            return $next($request);
        }

        $result = $this->subscriptionAccessService->evaluate($entitlements);

        if ((bool) ($result['allowed'] ?? false)) {
            return $next($request);
        }

        return response()->json([
            'code' => $result['code'] ?? 'FACILITY_ENTITLEMENT_REQUIRED',
            'message' => $result['message'] ?? 'This facility subscription does not include the requested service.',
            'requiredEntitlements' => $result['requiredEntitlements'] ?? [],
            'missingEntitlements' => $result['missingEntitlements'] ?? [],
            'facility' => $result['facility'] ?? null,
            'subscription' => $result['subscription'] ?? null,
        ], 403);
    }

    /**
     * @return array<int, string>
     */
    private function entitlementsForRequest(Request $request): array
    {
        $route = $request->route();
        $routeName = (string) ($route?->getName() ?? '');

        if ($routeName === '' || $this->shouldBypass($request, $routeName) || $this->hasExplicitEntitlementMiddleware($route)) {
            return [];
        }

        foreach ($this->entitlementMap() as $routePrefix => $entitlements) {
            if ($this->routeNameMatches($routeName, $routePrefix)) {
                return $entitlements;
            }
        }

        return [];
    }

    private function shouldBypass(Request $request, string $routeName): bool
    {
        $path = trim($request->path(), '/');

        return str_starts_with($routeName, 'auth.')
            || str_starts_with($routeName, 'platform.')
            || str_starts_with($path, 'api/v1/auth/')
            || str_starts_with($path, 'api/v1/platform/')
            || str_starts_with($path, 'v1/auth/')
            || str_starts_with($path, 'v1/platform/');
    }

    private function routeNameMatches(string $routeName, string $routePattern): bool
    {
        if (str_ends_with($routePattern, '.')) {
            return str_starts_with($routeName, $routePattern);
        }

        return $routeName === $routePattern;
    }

    private function hasExplicitEntitlementMiddleware(mixed $route): bool
    {
        if (! $route || ! method_exists($route, 'gatherMiddleware')) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'facility.entitlement')) {
                return true;
            }
        }

        return false;
    }
}
