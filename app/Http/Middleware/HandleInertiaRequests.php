<?php

namespace App\Http\Middleware;

use App\Modules\Platform\Application\Services\FacilitySubscriptionAccessService;
use App\Modules\Platform\Application\Services\ModuleRegistryService;
use App\Modules\Platform\Application\Support\CredentialLinkDeliveryPolicy;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Support\Auth\EffectivePermissionNameResolver;
use App\Support\Branding\SystemBrandingManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var SystemBrandingManager $brandingManager */
        $brandingManager = app(SystemBrandingManager::class);

        return [
            ...parent::share($request),
            'name' => $brandingManager->systemName(),
            'branding' => fn (): array => $brandingManager->publicBranding(),
            'auth' => [
                'user' => $request->user(),
                'permissions' => fn (): array => $this->permissionNames($request),
                'roleCodes' => fn (): array => $this->roleCodes($request),
                'isFacilitySuperAdmin' => fn (): bool => $this->isFacilitySuperAdmin($request),
                'isPlatformSuperAdmin' => fn (): bool => $this->isPlatformSuperAdmin($request),
                'hasFacilityAssignments' => fn (): bool => $this->hasFacilityAssignments($request),
            ],
            'platform' => [
                'scope' => fn (): ?array => $this->platformScope($request),
                'featureFlags' => fn (): array => $this->platformFeatureFlags(),
                'subscriptionAccess' => fn (): array => $this->platformSubscriptionAccess($request),
                'mail' => fn (): array => $this->platformMail(),
                'uploadLimits' => fn (): array => $this->platformUploadLimits(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'moduleNavCatalog' => fn (): array => app(ModuleRegistryService::class)->buildNavCatalog(),
            'moduleNavSections' => fn (): array => app(ModuleRegistryService::class)->navSections(),
            'moduleNavSectionOrder' => fn (): array => app(ModuleRegistryService::class)->navSectionOrder(),
            'moduleNavSubGroups' => fn (): array => app(ModuleRegistryService::class)->navSubGroups(),
            'facilityPathRules' => fn (): array => app(ModuleRegistryService::class)->buildFacilityPathRules(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function roleCodes(Request $request): array
    {
        $user = $request->user();
        if ($user === null || ! method_exists($user, 'roleCodes')) {
            return [];
        }

        return $user->roleCodes();
    }

    /**
     * @return array<int, string>
     */
    private function permissionNames(Request $request): array
    {
        $user = $request->user();
        if ($user === null || ! method_exists($user, 'permissionNames')) {
            return [];
        }

        /** @var EffectivePermissionNameResolver $resolver */
        $resolver = app(EffectivePermissionNameResolver::class);

        return $resolver->resolve($user, $user->permissionNames());
    }

    private function isFacilitySuperAdmin(Request $request): bool
    {
        $user = $request->user();
        if ($user === null || ! method_exists($user, 'isFacilitySuperAdminAccess')) {
            return false;
        }

        return (bool) $user->isFacilitySuperAdminAccess();
    }

    private function isPlatformSuperAdmin(Request $request): bool
    {
        $user = $request->user();
        if ($user === null || ! method_exists($user, 'isPlatformSuperAdminAccess')) {
            return false;
        }

        return (bool) $user->isPlatformSuperAdminAccess();
    }

    private function hasFacilityAssignments(Request $request): bool
    {
        $user = $request->user();
        if ($user === null || ! method_exists($user, 'hasFacilityAssignments')) {
            return false;
        }

        return (bool) $user->hasFacilityAssignments();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function platformScope(Request $request): ?array
    {
        if ($request->user() === null) {
            return null;
        }

        /** @var CurrentPlatformScopeContextInterface $scopeContext */
        $scopeContext = app(CurrentPlatformScopeContextInterface::class);

        return $scopeContext->toArray();
    }

    /**
     * @return array<string, bool>
     */
    private function platformFeatureFlags(): array
    {
        /** @var FeatureFlagResolverInterface $featureFlagResolver */
        $featureFlagResolver = app(FeatureFlagResolverInterface::class);

        return [
            'multiTenantIsolation' => $featureFlagResolver->isEnabled('platform.multi_tenant_isolation'),
            'multiFacilityScoping' => $featureFlagResolver->isEnabled('platform.multi_facility_scoping'),
            'walkInsRoutingSummaryOnPatientList' => $featureFlagResolver->isEnabled(
                'clinical.walk_ins.routing_summary_on_patient_list',
                true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function platformSubscriptionAccess(Request $request): array
    {
        if ($request->user() === null) {
            return [
                'accessEnabled' => false,
                'accessState' => 'guest',
                'code' => 'AUTH_REQUIRED',
                'message' => null,
                'facility' => null,
                'subscription' => null,
                'entitlementKeys' => [],
                'grantedEntitlements' => [],
            ];
        }

        try {
            if (! Schema::hasTable('facility_subscriptions') || ! Schema::hasTable('platform_subscription_plan_entitlements')) {
                return [
                    'accessEnabled' => false,
                    'accessState' => 'unavailable',
                    'code' => 'SUBSCRIPTION_TABLES_UNAVAILABLE',
                    'message' => 'Facility subscription tables are not ready yet.',
                    'facility' => null,
                    'subscription' => null,
                    'entitlementKeys' => [],
                    'grantedEntitlements' => [],
                ];
            }

            /** @var FacilitySubscriptionAccessService $subscriptionAccessService */
            $subscriptionAccessService = app(FacilitySubscriptionAccessService::class);

            return $subscriptionAccessService->currentAccessSummary();
        } catch (\Throwable) {
            return [
                'accessEnabled' => false,
                'accessState' => 'unavailable',
                'code' => 'SUBSCRIPTION_ACCESS_UNAVAILABLE',
                'message' => 'Facility subscription access could not be resolved.',
                'facility' => null,
                'subscription' => null,
                'entitlementKeys' => [],
                'grantedEntitlements' => [],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function platformMail(): array
    {
        $defaultMailer = (string) config('mail.default', 'log');
        /** @var SystemBrandingManager $brandingManager */
        $brandingManager = app(SystemBrandingManager::class);
        /** @var CredentialLinkDeliveryPolicy $credentialLinkDeliveryPolicy */
        $credentialLinkDeliveryPolicy = app(CredentialLinkDeliveryPolicy::class);

        return [
            'defaultMailer' => $defaultMailer,
            'fromName' => $brandingManager->mailFromName(),
            'fromAddress' => $brandingManager->mailFromAddress(),
            'replyToAddress' => $brandingManager->mailReplyToAddress(),
            'deliversExternally' => $credentialLinkDeliveryPolicy->deliversExternally(),
            'supportsCredentialLinkPreview' => $credentialLinkDeliveryPolicy->shouldReturnLocalPreview(),
            'warning' => $credentialLinkDeliveryPolicy->warning(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function platformUploadLimits(): array
    {
        $uploadMaxBytes = $this->parseIniSizeToBytes((string) ini_get('upload_max_filesize'));
        $postMaxBytes = $this->parseIniSizeToBytes((string) ini_get('post_max_size'));

        $effectiveMaxBytes = match (true) {
            $uploadMaxBytes > 0 && $postMaxBytes > 0 => min($uploadMaxBytes, $postMaxBytes),
            $uploadMaxBytes > 0 => $uploadMaxBytes,
            $postMaxBytes > 0 => $postMaxBytes,
            default => 20 * 1024 * 1024,
        };

        return [
            'documentMaxBytes' => $effectiveMaxBytes,
            'documentMaxLabel' => $this->formatBytesLabel($effectiveMaxBytes),
        ];
    }

    private function parseIniSizeToBytes(string $value): int
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return 0;
        }

        $unit = substr($normalized, -1);
        $number = (float) $normalized;

        return match ($unit) {
            'g' => (int) round($number * 1024 * 1024 * 1024),
            'm' => (int) round($number * 1024 * 1024),
            'k' => (int) round($number * 1024),
            default => (int) round((float) $normalized),
        };
    }

    private function formatBytesLabel(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 1, '.', ''), '0'), '.').'GB';
        }

        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.').'MB';
        }

        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.').'KB';
        }

        return $bytes.' bytes';
    }
}
