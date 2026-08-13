<?php

namespace App\Modules\Platform\Application\Services;

final class ModuleRegistryService
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return (array) config('modules.modules', []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function module(string $moduleId): ?array
    {
        $modules = $this->all();

        return $modules[$moduleId] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function enabledModuleIds(): array
    {
        return array_keys(array_filter($this->all(), fn (array $m): bool => (bool) ($m['enabled'] ?? false)));
    }

    /**
     * Route-prefix → entitlement-keys map for EnsureMappedFacilitySubscriptionEntitlement.
     *
     * @return array<string, array<int, string>>
     */
    public function buildRouteEntitlementMap(): array
    {
        $map = [];

        foreach ($this->all() as $module) {
            if (! ($module['enabled'] ?? false)) {
                continue;
            }

            $entitlementKey = (string) ($module['entitlement_key'] ?? '');

            if ($entitlementKey === '') {
                continue;
            }

            foreach ((array) ($module['route_prefixes'] ?? []) as $prefix) {
                $map[$prefix] = [$entitlementKey];
            }
        }

        return $map;
    }

    /**
     * @return array<int, array{path_prefix: string, required_all?: array<int, string>, required_any?: array<int, string>}>
     */
    public function buildFacilityPathRules(): array
    {
        $rules = [];

        foreach ($this->all() as $module) {
            if (! ($module['enabled'] ?? false)) {
                continue;
            }

            foreach ((array) ($module['facility_path_rules'] ?? []) as $rule) {
                $entry = [
                    'path_prefix' => (string) ($rule['path_prefix'] ?? ''),
                ];

                if (isset($rule['required_all']) && $rule['required_all'] !== []) {
                    $entry['required_all'] = (array) $rule['required_all'];
                }

                if (isset($rule['required_any']) && $rule['required_any'] !== []) {
                    $entry['required_any'] = (array) $rule['required_any'];
                }

                $rules[] = $entry;
            }
        }

        return $rules;
    }

    /**
     * @return array<int, array{title: string, href: string, icon: string, section: string, sub_group?: string, permission_prefixes: array<int, string>, help_note?: string}>
     */
    public function buildNavCatalog(): array
    {
        $items = [];

        foreach ($this->all() as $module) {
            if (! ($module['enabled'] ?? false)) {
                continue;
            }

            $nav = $module['nav'] ?? null;

            if ($nav === null) {
                continue;
            }

            $item = [
                'title' => (string) ($nav['title'] ?? ''),
                'href' => (string) ($nav['href'] ?? ''),
                'icon' => (string) ($nav['icon'] ?? ''),
                'section' => (string) ($nav['section'] ?? ''),
                'permission_prefixes' => (array) ($nav['permission_prefixes'] ?? []),
            ];

            if (isset($nav['sub_group'])) {
                $item['sub_group'] = (string) $nav['sub_group'];
            }

            if (isset($nav['help_note'])) {
                $item['help_note'] = (string) $nav['help_note'];
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function navSections(): array
    {
        return (array) config('modules.nav_sections', []);
    }

    /**
     * @return array<int, string>
     */
    public function navSectionOrder(): array
    {
        return (array) config('modules.nav_section_order', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function navSubGroups(): array
    {
        return (array) config('modules.nav_sub_groups', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function workflowDefinitions(): array
    {
        return (array) config('modules.workflows', []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function workflow(string $workflowKey): ?array
    {
        $workflows = $this->workflowDefinitions();

        return $workflows[$workflowKey] ?? null;
    }

    /**
     * Dashboard widgets aggregated from all modules, keyed by workflow.
     *
     * @return array<string, array<int, array{id: string, label: string, permission: string}>>
     */
    public function buildWorkflowWidgets(): array
    {
        $widgets = [];

        foreach ($this->all() as $module) {
            if (! ($module['enabled'] ?? false)) {
                continue;
            }

            foreach ((array) ($module['dashboard_widgets'] ?? []) as $widget) {
                $workflowKey = (string) ($widget['workflow'] ?? '');
                if ($workflowKey === '') {
                    continue;
                }
                $widgets[$workflowKey][] = [
                    'id' => (string) ($widget['id'] ?? ''),
                    'label' => (string) ($widget['label'] ?? ''),
                    'permission' => (string) ($widget['permission'] ?? ''),
                ];
            }
        }

        return $widgets;
    }

    /**
     * @return array<int, string>
     */
    public function permissionsForModule(string $moduleId): array
    {
        $module = $this->module($moduleId);

        if ($module === null) {
            return [];
        }

        return (array) ($module['permissions'] ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entitlementSeeding(string $moduleId): ?array
    {
        $module = $this->module($moduleId);

        return $module['entitlement_seeding'] ?? null;
    }
}
