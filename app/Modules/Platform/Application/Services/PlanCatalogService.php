<?php

namespace App\Modules\Platform\Application\Services;

/**
 * Reads the 2027 plan catalog (config/plan_catalog.php) and resolves the
 * flat set of entitlement keys each plan actually grants.
 *
 * The catalog stores capabilities (grouped product SKUs) and per-plan
 * capability assignments plus feature-level exclusions and quota limits.
 * This service expands that into the fine-grained entitlement_key list the
 * rest of the platform (middleware, access service, seeding) expects.
 */
class PlanCatalogService
{
    /**
     * Current catalog version string.
     */
    public function version(): string
    {
        return (string) config('plan_catalog.version', 'dev');
    }

    /**
     * All capability definitions keyed by capability id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function capabilities(): array
    {
        return (array) config('plan_catalog.capabilities', []);
    }

    /**
     * All plan definitions keyed by plan code.
     *
     * @return array<string, array<string, mixed>>
     */
    public function plans(): array
    {
        return (array) config('plan_catalog.plans', []);
    }

    /**
     * Feature metadata (label, permissions) for a given entitlement key.
     * Returns null when the key is not present in the catalog.
     *
     * @return array{label: string, permissions: array<int, string>, capability: string}|null
     */
    public function feature(string $entitlementKey): ?array
    {
        foreach ($this->capabilities() as $capabilityId => $capability) {
            foreach ((array) ($capability['features'] ?? []) as $candidateKey => $feature) {
                if ($candidateKey === $entitlementKey) {
                    return [
                        'label' => (string) ($feature['label'] ?? $entitlementKey),
                        'permissions' => array_values(array_map('strval', (array) ($feature['permissions'] ?? []))),
                        'capability' => $capabilityId,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Every entitlement key defined across all capabilities.
     *
     * @return array<int, string>
     */
    public function allEntitlementKeys(): array
    {
        $keys = [];
        foreach ($this->capabilities() as $capability) {
            foreach (array_keys((array) ($capability['features'] ?? [])) as $key) {
                $keys[] = (string) $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The entitlements a plan actually grants:
     *   every feature of every assigned capability,
     *   minus plan_feature_exclusions[planCode],
     *   plus additive plan_features[planCode] (platform-wide grants).
     *
     * @return array<int, string>
     */
    public function entitlementsForPlan(string $planCode): array
    {
        $keys = [];
        $capabilities = $this->capabilities();
        $assigned = (array) (config("plan_catalog.plan_capabilities.{$planCode}", []));
        $excluded = (array) (config("plan_catalog.plan_feature_exclusions.{$planCode}", []));
        $excluded = array_flip(array_map('strval', $excluded));

        foreach ($assigned as $capabilityId) {
            $capability = $capabilities[$capabilityId] ?? null;
            if (! is_array($capability)) {
                continue;
            }

            foreach (array_keys((array) ($capability['features'] ?? [])) as $key) {
                $key = (string) $key;
                if (! isset($excluded[$key])) {
                    $keys[] = $key;
                }
            }
        }

        foreach ((array) (config("plan_catalog.plan_features.{$planCode}", [])) as $key) {
            $key = (string) $key;
            if (! isset($excluded[$key])) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * All entitlement keys a plan grants, indexed for fast lookups.
     *
     * @return array<string, true>
     */
    public function entitlementIndexForPlan(string $planCode): array
    {
        return array_fill_keys($this->entitlementsForPlan($planCode), true);
    }

    /**
     * Validate that every entitlement key referenced in plan assignments and
     * additive grants exists in the catalog. Returns missing keys.
     *
     * @return array<int, string>
     */
    public function missingCatalogKeys(): array
    {
        $defined = array_fill_keys($this->allEntitlementKeys(), true);
        $missing = [];

        foreach (array_keys($this->plans()) as $planCode) {
            foreach ($this->entitlementsForPlan((string) $planCode) as $key) {
                if (! isset($defined[$key])) {
                    $missing[] = $key;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Resolve quota limits for a plan. Returns capability limit defaults
     * merged with plan_limits overrides.
     *
     * @return array<string, int|null>
     */
    public function limitsForPlan(string $planCode): array
    {
        $limits = [];
        foreach ($this->capabilities() as $capability) {
            foreach ((array) ($capability['limits'] ?? []) as $limitKey => $limit) {
                $limits[(string) $limitKey] = isset($limit['default']) && $limit['default'] !== null
                    ? (int) $limit['default']
                    : null;
            }
        }

        foreach ((array) (config("plan_catalog.plan_limits.{$planCode}", [])) as $limitKey => $value) {
            $limits[(string) $limitKey] = $value === null ? null : (int) $value;
        }

        return $limits;
    }

    /**
     * Feature dependency map (feature key -> list of required feature keys).
     *
     * @return array<string, array<int, string>>
     */
    public function dependencies(): array
    {
        $dependencies = [];
        foreach ($this->capabilities() as $capability) {
            foreach ((array) ($capability['dependencies'] ?? []) as $featureKey => $requires) {
                $dependencies[(string) $featureKey] = array_values(array_map('strval', (array) $requires));
            }
        }

        return $dependencies;
    }
}