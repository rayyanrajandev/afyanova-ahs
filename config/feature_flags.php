<?php

return [
    'flags' => [
        'platform.country_profile.enforced' => [
            'enabled' => true,
            'owner' => 'platform',
            'stage' => 'beta',
            'description' => 'Use country profile config to drive regional behavior.',
        ],
        'platform.feature_flags.api' => [
            'enabled' => true,
            'owner' => 'platform',
            'stage' => 'beta',
            'description' => 'Expose effective feature flags to authenticated clients.',
        ],
        'platform.multi_facility_scoping' => [
            'enabled' => false,
            'owner' => 'platform',
            'stage' => 'planned',
            'description' => 'Enforce facility scope filters on all domain reads/writes.',
        ],
        'platform.multi_tenant_isolation' => [
            'enabled' => false,
            'owner' => 'platform',
            'stage' => 'planned',
            'description' => 'Enable tenant isolation primitives and middleware enforcement.',
        ],
        'inventory.catalog_first_reads' => [
            'enabled' => true,
            'owner' => 'inventory',
            'stage' => 'beta',
            'description' => 'Inventory_MasterData_Alignment_Plan.md Phase 2: read storage conditions, cold-chain, and controlled-substance status from the linked Clinical Catalog item instead of the inventory row. Flip back to false to roll back the cutover without a deploy.',
        ],
        'platform.localization.swahili' => [
            'enabled' => true,
            'owner' => 'platform',
            'stage' => 'beta',
            'description' => 'Enable Swahili language pack support in critical workflows.',
        ],
        'billing.multi_currency' => [
            'enabled' => true,
            'owner' => 'billing',
            'stage' => 'beta',
            'description' => 'Allow billing workflows to process configured multiple currencies.',
        ],
        'laboratory.loinc_required' => [
            'enabled' => false,
            'owner' => 'laboratory',
            'stage' => 'planned',
            'description' => 'Require LOINC code validation for laboratory orders in enabled profiles.',
        ],
        'clinical.walk_ins.routing_summary_on_patient_list' => [
            'enabled' => true,
            'owner' => 'clinical',
            'stage' => 'beta',
            'description' => 'When enabled, patient index includes active walk-in (service request) summaries for anyone with patients.read—not only service.requests readers.',
        ],
    ],
];
