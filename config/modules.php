<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nav Sections
    |--------------------------------------------------------------------------
    */
    'nav_sections' => [
        'front_office' => ['label' => 'Registration & visits', 'icon' => 'calendar-clock'],
        'clinical_care' => ['label' => 'Clinical care', 'icon' => 'heart-pulse'],
        'diagnostics' => ['label' => 'Diagnostics & pharmacy', 'icon' => 'stethoscope'],
        'billing' => ['label' => 'Billing & insurance', 'icon' => 'circle-check-big'],
        'stores' => ['label' => 'Stores & supply', 'icon' => 'package'],
        'people' => ['label' => 'People & credentials', 'icon' => 'users'],
        'facility_setup' => ['label' => 'Facility setup', 'icon' => 'building-2'],
        'system_access' => ['label' => 'Users & system', 'icon' => 'shield-check'],
    ],

    'nav_section_order' => [
        'front_office', 'clinical_care', 'diagnostics', 'billing',
        'stores', 'people', 'facility_setup', 'system_access',
    ],

    'nav_sub_groups' => [
        'clinical_care' => [
            'records' => ['label' => 'Records', 'icon' => 'folder'],
        ],
        'billing' => [
            'invoicing' => ['label' => 'Invoicing', 'icon' => 'receipt'],
            'point_of_sale' => ['label' => 'Point of sale', 'icon' => 'shopping-cart'],
            'rates' => ['label' => 'Coverage & rates', 'icon' => 'shield-check'],
            'reports' => ['label' => 'Reporting', 'icon' => 'file-text'],
        ],
        'stores' => [
            'transactions' => ['label' => 'Transactions', 'icon' => 'arrow-up-down'],
            'admin' => ['label' => 'Administration', 'icon' => 'list'],
        ],
        'facility_setup' => [
            'foundation' => ['label' => 'Foundation', 'icon' => 'building-2'],
            'catalog' => ['label' => 'Clinical Catalog', 'icon' => 'book-open'],
            'subscriptions' => ['label' => 'Subscriptions', 'icon' => 'receipt'],
        ],
        'people' => [
            'staff' => ['label' => 'Staff', 'icon' => 'users'],
            'credentials' => ['label' => 'Credentials', 'icon' => 'shield-check'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Module Definitions
    |--------------------------------------------------------------------------
    |
    | Single source of truth for feature modules. Each entry drives:
    |   - Navigation entries (appNavCatalog)
    |   - Route entitlement mapping (EnsureMappedFacilitySubscriptionEntitlement)
    |   - Facility path entitlement rules (facilityPageEntitlements.ts)
    |   - Dashboard widgets
    |   - Permission seeding
    |   - Subscription plan entitlement seeding
    |
    | To add a new module: add one entry here, everything else auto-wires.
    |
    */
    'modules' => [

        'clinical_procedure' => [
            'enabled' => true,
            'label' => 'Clinical Procedures',
            'nav' => [
                'title' => 'Clinical procedures',
                'href' => '/clinical-procedure-orders',
                'icon' => 'layers',
                'section' => 'clinical_care',
                'permission_prefixes' => ['clinical-procedure.'],
                'help_note' => 'Nursing and bedside procedure orders',
            ],
            'entitlement_key' => 'clinical_procedure.orders',
            'route_prefixes' => ['clinical-procedure-orders.'],
            'facility_path_rules' => [
                ['path_prefix' => '/clinical-procedure-orders', 'required_all' => ['clinical_procedure.orders']],
            ],
            'dashboard_widgets' => [
                ['workflow' => 'direct_service', 'id' => 'clinical_procedures', 'label' => 'Clinical Procedures', 'permission' => 'clinical-procedure.orders.read'],
            ],
            'permissions' => [
                'clinical-procedure.orders.read',
                'clinical-procedure.order',
                'clinical-procedure.orders.update',
                'clinical-procedure.perform',
                'clinical-procedure.orders.view-audit-logs',
            ],
            'entitlement_seeding' => [
                'label' => 'Clinical procedure orders, worklist, and results',
                'group' => 'Care Delivery',
            ],
        ],

        'laboratory' => [
            'enabled' => true,
            'label' => 'Laboratory',
            'nav' => [
                'title' => 'Laboratory',
                'href' => '/laboratory-orders',
                'icon' => 'flask-conical',
                'section' => 'diagnostics',
                'permission_prefixes' => ['laboratory.orders.', 'laboratory-orders.'],
                'help_note' => 'Lab order queue and result status updates',
            ],
            'entitlement_key' => 'laboratory.orders',
            'route_prefixes' => ['laboratory-orders.'],
            'facility_path_rules' => [
                ['path_prefix' => '/laboratory-orders', 'required_all' => ['laboratory.orders']],
            ],
            'dashboard_widgets' => [
                ['workflow' => 'direct_service', 'id' => 'laboratory', 'label' => 'Laboratory', 'permission' => 'laboratory.orders.read'],
                ['workflow' => 'clinician', 'id' => 'orders', 'label' => 'Downstream orders', 'permission' => 'laboratory.orders.read'],
                ['workflow' => 'nursing', 'id' => 'orders', 'label' => 'Stat orders', 'permission' => 'laboratory.orders.read'],
                ['workflow' => 'emergency', 'id' => 'orders', 'label' => 'Stat orders', 'permission' => 'laboratory.orders.read'],
            ],
        ],

        'pharmacy' => [
            'enabled' => true,
            'label' => 'Pharmacy',
            'nav' => [
                'title' => 'Pharmacy & dispensing',
                'href' => '/pharmacy-orders',
                'icon' => 'pill',
                'section' => 'diagnostics',
                'permission_prefixes' => ['pharmacy.orders.', 'pharmacy-orders.'],
                'help_note' => 'Dispense queue and medication fulfillment',
            ],
            'entitlement_key' => 'pharmacy.orders',
            'route_prefixes' => ['pharmacy-orders.'],
            'facility_path_rules' => [
                ['path_prefix' => '/pharmacy-orders', 'required_all' => ['pharmacy.orders']],
            ],
            'dashboard_widgets' => [
                ['workflow' => 'direct_service', 'id' => 'pharmacy', 'label' => 'Pharmacy', 'permission' => 'pharmacy.orders.read'],
            ],
        ],

        'radiology' => [
            'enabled' => true,
            'label' => 'Radiology',
            'nav' => [
                'title' => 'Imaging & radiology',
                'href' => '/radiology-orders',
                'icon' => 'activity',
                'section' => 'diagnostics',
                'permission_prefixes' => ['radiology.orders.'],
                'help_note' => 'Imaging orders across modalities',
            ],
            'entitlement_key' => 'radiology.orders',
            'route_prefixes' => ['radiology-orders.'],
            'facility_path_rules' => [
                ['path_prefix' => '/radiology-orders', 'required_all' => ['radiology.orders']],
            ],
            'dashboard_widgets' => [
                ['workflow' => 'direct_service', 'id' => 'radiology', 'label' => 'Radiology', 'permission' => 'radiology.orders.read'],
            ],
        ],

        'emergency' => [
            'enabled' => true,
            'label' => 'Emergency',
            'entitlement_key' => 'emergency.triage',
            'route_prefixes' => ['emergency-triage.', 'emergency.'],
            'facility_path_rules' => [
                ['path_prefix' => '/emergency-triage', 'required_all' => ['emergency.triage']],
                ['path_prefix' => '/emergency', 'required_all' => ['emergency.triage']],
            ],
        ],

        'theatre' => [
            'enabled' => true,
            'label' => 'Theatre',
            'nav' => [
                'title' => 'Operating theatre',
                'href' => '/theatre-procedures',
                'icon' => 'scissors',
                'section' => 'clinical_care',
                'permission_prefixes' => ['theatre.procedures.'],
                'help_note' => 'Procedure scheduling and perioperative workflow',
            ],
            'entitlement_key' => 'theatre.procedures',
            'route_prefixes' => ['theatre-procedures.'],
            'facility_path_rules' => [
                ['path_prefix' => '/theatre-procedures', 'required_all' => ['theatre.procedures']],
            ],
        ],

        'inpatient_ward' => [
            'enabled' => true,
            'label' => 'Inpatient Ward',
            'nav' => [
                'title' => 'Ward management',
                'href' => '/inpatient-ward',
                'icon' => 'clipboard-list',
                'section' => 'clinical_care',
                'permission_prefixes' => ['inpatient.ward.'],
                'help_note' => 'Ward census, nursing tasks, and bedside workflow',
            ],
            'entitlement_key' => 'inpatient.ward',
            'route_prefixes' => ['inpatient-ward.'],
            'facility_path_rules' => [
                ['path_prefix' => '/inpatient-ward', 'required_all' => ['inpatient.ward']],
            ],
        ],

        'medical_records' => [
            'enabled' => true,
            'label' => 'Medical Records',
            'entitlement_key' => 'medical_records.core',
            'route_prefixes' => ['medical-records.'],
            'facility_path_rules' => [
                ['path_prefix' => '/medical-records', 'required_all' => ['medical_records.core']],
            ],
        ],

        'billing_invoices' => [
            'enabled' => true,
            'label' => 'Billing Invoices',
            'nav' => [
                'title' => 'Invoices & billing',
                'href' => '/billing',
                'icon' => 'receipt',
                'section' => 'billing',
                'sub_group' => 'invoicing',
                'permission_prefixes' => ['billing.invoices.', 'billing-invoices.'],
                'help_note' => 'Invoice queue, board, and create invoices',
            ],
            'entitlement_key' => 'billing.invoices',
            'route_prefixes' => ['billing-invoices.'],
            'facility_path_rules' => [
                ['path_prefix' => '/billing', 'required_all' => ['billing.invoices']],
            ],
        ],

        'claims_insurance' => [
            'enabled' => true,
            'label' => 'Claims & Insurance',
            'nav' => [
                'title' => 'NHIF & insurance',
                'href' => '/claims-insurance',
                'icon' => 'shield-check',
                'section' => 'billing',
                'sub_group' => 'rates',
                'permission_prefixes' => ['claims.insurance.'],
                'help_note' => 'NHIF, private payers, pre-auth, and claim adjudication',
            ],
            'entitlement_key' => 'claims.insurance',
            'route_prefixes' => ['claims-insurance.'],
            'facility_path_rules' => [
                ['path_prefix' => '/claims-insurance', 'required_all' => ['claims.insurance']],
            ],
        ],

        'inventory_procurement' => [
            'enabled' => true,
            'label' => 'Supply Chain',
            'nav' => [
                'title' => 'Supply chain',
                'href' => '/inventory-procurement',
                'icon' => 'package',
                'section' => 'stores',
                'sub_group' => 'transactions',
                'permission_prefixes' => ['inventory.procurement.'],
                'help_note' => 'Hospital stores, procurement, and stock tasks',
            ],
            'entitlement_key' => 'inventory.procurement',
            'route_prefixes' => ['inventory-procurement.'],
            'facility_path_rules' => [
                ['path_prefix' => '/inventory-procurement', 'required_all' => ['inventory.procurement']],
            ],
        ],

        'staff_directory' => [
            'enabled' => true,
            'label' => 'Staff Directory',
            'nav' => [
                'title' => 'Staff directory',
                'href' => '/staff',
                'icon' => 'users',
                'section' => 'people',
                'sub_group' => 'staff',
                'permission_prefixes' => ['staff.'],
                'help_note' => 'Staff profiles and employment status',
            ],
            'entitlement_key' => 'staff.profiles',
            'route_prefixes' => ['staff.'],
            'facility_path_rules' => [
                ['path_prefix' => '/staff', 'required_all' => ['staff.profiles']],
                ['path_prefix' => '/staff-credentialing', 'required_all' => ['staff.credentialing']],
                ['path_prefix' => '/staff-privileges', 'required_all' => ['staff.privileges']],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow Definitions
    |--------------------------------------------------------------------------
    |
    | Dashboard workflows are cross-module by nature. Each workflow defines
    | role eligibility, facility-entitlement gating, its catalog entry,
    | and any per-role presentation overrides (e.g. Direct Service).
    |
    */
    'workflows' => [

        'admin' => [
            'label' => 'Admin',
            'description' => 'Monitor platform health, scope coverage, and operational controls across modules.',
            'modules' => ['Audit Export', 'Users', 'Facility Config'],
            'role_codes' => [
                'PLATFORM.USER.ADMIN',
                'PLATFORM.RBAC.ADMIN',
                'PLATFORM.SUBSCRIPTION.ADMIN',
                'ADMIN.FACILITY',
            ],
            'widgets' => [
                ['id' => 'audit_export', 'label' => 'Audit export health', 'permission' => 'platform.users.read'],
                ['id' => 'scope', 'label' => 'Facility scope', 'permission' => 'platform.facilities.read'],
            ],
            'facility_entitlements' => null, // bypass for admin
        ],

        'front_desk' => [
            'label' => 'Front Desk',
            'description' => 'Keep arrivals, registration, and appointment handoffs moving without losing queue context.',
            'modules' => ['Patients', 'Appointments', 'Admissions'],
            'role_codes' => ['ADMIN.REGISTRATION'],
            'widgets' => [
                ['id' => 'patients', 'label' => 'Patient census', 'permission' => 'patients.read'],
                ['id' => 'appointments', 'label' => 'Appointment queue', 'permission' => 'appointments.read'],
                ['id' => 'admissions', 'label' => 'Admissions', 'permission' => 'admissions.read'],
            ],
            'facility_entitlements' => [
                'all' => ['patients.search', 'appointments.scheduling'],
            ],
        ],

        'clinician' => [
            'label' => 'Clinician',
            'description' => 'Stay focused on consultation-ready encounters, open notes, and inpatient follow-up load.',
            'modules' => ['Appointments', 'Medical Records', 'Admissions'],
            'role_codes' => ['CLINICAL.PHYSICIAN', 'CLINICAL.GENERAL'],
            'widgets' => [
                ['id' => 'appointments', 'label' => 'Consultation queue', 'permission' => 'appointments.read'],
                ['id' => 'medical_records', 'label' => 'Medical records', 'permission' => 'medical.records.read'],
            ],
            'facility_entitlements' => [
                'any' => ['medical_records.core', 'appointments.scheduling'],
            ],
        ],

        'nursing' => [
            'label' => 'Nursing',
            'description' => 'Monitor triage queue, inpatient movement, and downstream orders that block bedside care.',
            'modules' => ['Triage', 'Admissions', 'Inpatient Ward'],
            'role_codes' => ['CLINICAL.NURSE'],
            'widgets' => [
                ['id' => 'triage', 'label' => 'Triage queue', 'permission' => 'appointments.read'],
                ['id' => 'ward', 'label' => 'Inpatient ward', 'permission' => 'inpatient.ward.read'],
            ],
            'facility_entitlements' => [
                'any' => ['inpatient.ward', 'appointments.scheduling'],
            ],
        ],

        'emergency' => [
            'label' => 'Emergency',
            'description' => 'Triage queue sorted by arrival time, stat orders, and real-time admission load for emergency and acute-care staff.',
            'modules' => ['Triage', 'Admissions', 'Laboratory', 'Pharmacy'],
            'role_codes' => ['CLINICAL.EMERGENCY'],
            'widgets' => [
                ['id' => 'triage', 'label' => 'ED triage', 'permission' => 'emergency.triage.read'],
                ['id' => 'admissions', 'label' => 'Admissions', 'permission' => 'admissions.read'],
            ],
            'facility_entitlements' => [
                'any' => ['emergency.triage', 'appointments.scheduling'],
            ],
        ],

        'direct_service' => [
            'label' => 'Direct Service',
            'description' => 'Watch laboratory, pharmacy, radiology, and clinical procedure queues without borrowing nursing-only census signals.',
            'modules' => ['Laboratory', 'Pharmacy', 'Radiology', 'Clinical Procedures'],
            'role_codes' => ['LAB.STAFF', 'PHARMACY.STAFF', 'RADIOLOGY.STAFF'],
            'facility_entitlements' => [
                'any' => ['laboratory.orders', 'pharmacy.orders', 'radiology.orders', 'clinical_procedure.orders'],
            ],
            // Per-role presentation overrides when user has exactly one direct-service role
            'role_labels' => [
                'LAB.STAFF' => ['label' => 'Laboratory', 'description' => 'Laboratory order queue, specimen processing, and result verification for bench staff.'],
                'LAB.SUPERVISOR' => ['label' => 'Laboratory', 'description' => 'Laboratory order queue, specimen processing, and result verification for bench staff.'],
                'LAB.MANAGER' => ['label' => 'Laboratory', 'description' => 'Laboratory order queue, specimen processing, and result verification for bench staff.'],
                'PHARMACY.STAFF' => ['label' => 'Pharmacy', 'description' => 'Pharmacy dispensing queue, order preparation, and verification for dispensary staff.'],
                'PHARMACY.SUPERVISOR' => ['label' => 'Pharmacy', 'description' => 'Pharmacy dispensing queue, order preparation, and verification for dispensary staff.'],
                'PHARMACY.MANAGER' => ['label' => 'Pharmacy', 'description' => 'Pharmacy dispensing queue, order preparation, and verification for dispensary staff.'],
                'RADIOLOGY.STAFF' => ['label' => 'Radiology', 'description' => 'Imaging order queue, scheduling, and reporting for radiology staff.'],
                'RADIOLOGY.SUPERVISOR' => ['label' => 'Radiology', 'description' => 'Imaging order queue, scheduling, and reporting for radiology staff.'],
                'RADIOLOGY.MANAGER' => ['label' => 'Radiology', 'description' => 'Imaging order queue, scheduling, and reporting for radiology staff.'],
            ],
            // Per-permission presentation overrides when user has exactly one module permission
            'permission_labels' => [
                'laboratory.orders.read' => ['label' => 'Laboratory', 'description' => 'Laboratory order queue, specimen processing, and result verification for bench staff.'],
                'pharmacy.orders.read' => ['label' => 'Pharmacy', 'description' => 'Pharmacy dispensing queue, order preparation, and verification for dispensary staff.'],
                'radiology.orders.read' => ['label' => 'Radiology', 'description' => 'Imaging order queue, scheduling, and reporting for radiology staff.'],
                'clinical_procedure.orders.read' => ['label' => 'Clinical Procedures', 'description' => 'Clinical procedure order queue, execution, and documentation for nursing and clinical staff.'],
            ],
        ],

        'cashier' => [
            'label' => 'Cashier',
            'description' => 'Prioritize invoice follow-up and payer exception handling from a single landing view.',
            'modules' => ['Billing', 'Claims', 'Pharmacy'],
            'role_codes' => ['FINANCE.CASHIER', 'FINANCE.OFFICER', 'FINANCE.CONTROLLER'],
            'widgets' => [
                ['id' => 'billing', 'label' => 'Billing drafts', 'permission' => 'billing.invoices.read'],
                ['id' => 'claims', 'label' => 'Claim exceptions', 'permission' => 'claims.insurance.read'],
            ],
            'facility_entitlements' => [
                'any' => ['billing.invoices', 'claims.insurance'],
            ],
        ],

        'operations' => [
            'label' => 'Operations',
            'description' => 'Staff directory, credentialing compliance, and privileging queues for HR and quality teams.',
            'modules' => ['Staff', 'Credentialing', 'Privileges'],
            'role_codes' => ['ADMIN.HR'],
            'widgets' => [
                ['id' => 'staff', 'label' => 'Staff census', 'permission' => 'staff.read'],
                ['id' => 'credentialing', 'label' => 'Credentialing alerts', 'permission' => 'staff.credentialing.read'],
                ['id' => 'privileges', 'label' => 'Privileging queue', 'permission' => 'staff.privileges.read'],
            ],
            'facility_entitlements' => [
                'any' => ['staff.profiles', 'staff.credentialing', 'staff.privileges'],
            ],
        ],

        'records' => [
            'label' => 'Medical Records',
            'description' => 'Health information workflows focused on chart completeness, release, and record governance.',
            'modules' => ['Medical Records', 'Patients', 'Audit'],
            'role_codes' => ['ADMIN.MEDICAL.RECORDS'],
            'widgets' => [
                ['id' => 'draft_records', 'label' => 'Draft records', 'permission' => 'medical.records.read'],
                ['id' => 'patients', 'label' => 'Patient lookup', 'permission' => 'patients.read'],
            ],
            'facility_entitlements' => [
                'all' => ['medical_records.core'],
            ],
        ],

        'supply' => [
            'label' => 'Supply Chain',
            'description' => 'Inventory alerts, stock movement, and procurement requests for storekeepers.',
            'modules' => ['Inventory', 'Procurement', 'Suppliers'],
            'role_codes' => ['INVENTORY.STAFF', 'INVENTORY.SUPERVISOR', 'INVENTORY.MANAGER'],
            'widgets' => [
                ['id' => 'stock_alerts', 'label' => 'Stock alerts', 'permission' => 'inventory.procurement.read'],
                ['id' => 'procurement', 'label' => 'Procurement requests', 'permission' => 'inventory.procurement.read'],
            ],
            'facility_entitlements' => [
                'all' => ['inventory.procurement'],
            ],
        ],

        'theatre' => [
            'label' => 'Theatre',
            'description' => 'Procedure scheduling, OR resource allocation, and perioperative status at a glance.',
            'modules' => ['Theatre Procedures', 'Resource Allocation'],
            'role_codes' => ['THEATRE.STAFF', 'THEATRE.SUPERVISOR', 'THEATRE.MANAGER'],
            'widgets' => [
                ['id' => 'schedule', 'label' => 'Procedure schedule', 'permission' => 'theatre.procedures.read'],
                ['id' => 'status', 'label' => 'Status counts', 'permission' => 'theatre.procedures.read'],
            ],
            'facility_entitlements' => [
                'all' => ['theatre.procedures'],
            ],
        ],
    ],
];
