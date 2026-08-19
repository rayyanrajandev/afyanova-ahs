<?php

/**
 * |--------------------------------------------------------------------------
 * | Subscription Plan Catalog — 2027 Enterprise Model
 * |--------------------------------------------------------------------------
 * |
 * | Single source of truth for the subscription plan system. Uses a modern
 * | 3-level model:
 * |
 * |   Plan (SKU) ── has ──▶ Capabilities (grouped product features)
 * |                                ├── Features (fine-grained entitlement keys)
 * |                                ├── Dependencies (feature requires feature)
 * |                                └── Limits (quota schema, enforced per plan)
 * |
 * | The "Features" layer reuses the existing flat entitlement keys from
 * | platform_subscription_plan_entitlements (entitlement_key) so the existing
 * | DB rows, middleware, and access service keep working unchanged. The
 * | capability layer is what admins configure per plan.
 * |
 * | To add a NEW feature (2027 flow):
 * |   1. Add the key under the appropriate capability's 'features' with label
 * |      and associated RBAC permissions.
 * |   2. Add any 'dependencies' (features this key requires).
 * |   3. Add the capability (or specific feature) to the intended plans in
 * |      'plan_capabilities'.
 * |   4. Add route annotations / route-map entries requiring the new key.
 * |
 * | To add a new plan: add 'plans' meta + 'plan_capabilities' + optional
 * | 'plan_limits' overrides.
 */

return [

    'version' => '2027.1',

    /*
    | Capabilities: the grouped product SKUs a facility subscribes to.
    | Each capability owns a set of fine-grained features (existing
    | entitlement keys). Feature metadata carries the RBAC permissions that
    | users of a plan granting this feature should receive.
    |
    | 'limits' defines quota keys the capability can control. 'default' null
    | means unlimited unless a plan override sets a number.
    */
    'capabilities' => [

        'patient_access' => [
            'label' => 'Patient Access',
            'icon' => 'user-round',
            'description' => 'Patient registration, search, demographics, and medication safety profile.',
            'features' => [
                'patients.registration' => [
                    'label' => 'Patient registration',
                    'permissions' => ['patients.read', 'patients.create'],
                ],
                'patients.search' => [
                    'label' => 'Patient search and chart lookup',
                    'permissions' => ['patients.read', 'patients.view-audit-logs'],
                ],
                'patients.demographics' => [
                    'label' => 'Demographics and patient status maintenance',
                    'permissions' => ['patients.update', 'patients.update-status'],
                ],
                'patients.medication_safety' => [
                    'label' => 'Allergies, medication profile, and reconciliation',
                    'permissions' => ['patients.read', 'patients.update'],
                ],
            ],
            'dependencies' => [],
            'limits' => [
                'patients.monthly' => ['label' => 'Monthly patient registrations', 'unit' => 'registrations', 'default' => null],
            ],
        ],

        'front_office' => [
            'label' => 'Front Office',
            'icon' => 'calendar-clock',
            'description' => 'Appointments, provider sessions, referrals, admissions intake, and the walk-in service request queue.',
            'features' => [
                'appointments.scheduling' => [
                    'label' => 'Appointments, queues, and scheduling',
                    'permissions' => ['appointments.read', 'appointments.create', 'appointments.update', 'appointments.update-status', 'appointments.record-triage', 'appointments.view-audit-logs'],
                ],
                'appointments.provider_sessions' => [
                    'label' => 'Provider sessions and consultation start',
                    'permissions' => ['appointments.manage-provider-session', 'appointments.start-consultation'],
                ],
                'appointments.referrals' => [
                    'label' => 'Referral management and referral audit',
                    'permissions' => ['appointments.manage-referrals', 'appointments.view-referral-audit-logs'],
                ],
                'admissions.management' => [
                    'label' => 'Admissions, bed occupancy, and admission audit',
                    'permissions' => ['admissions.read', 'admissions.create', 'admissions.update', 'admissions.update-status', 'admissions.view-audit-logs'],
                ],
                'clinical.walk_in_queue' => [
                    'label' => 'Direct service request queue',
                    'permissions' => ['service.requests.read', 'service.requests.create', 'service.requests.update-status', 'service.requests.export', 'service.requests.audit-logs.read'],
                ],
            ],
            'dependencies' => [
                'appointments.provider_sessions' => ['appointments.scheduling'],
                'appointments.referrals' => ['appointments.scheduling'],
            ],
            'limits' => [],
        ],

        'emergency_care' => [
            'label' => 'Emergency & Triage',
            'icon' => 'siren',
            'description' => 'Emergency triage, transfers, and acute-care desk.',
            'features' => [
                'emergency.triage' => [
                    'label' => 'Emergency triage and transfer desk',
                    'permissions' => ['emergency.triage.read', 'emergency.triage.create', 'emergency.triage.update', 'emergency.triage.update-status', 'emergency.triage.manage-transfers', 'emergency.triage.view-transfer-audit-logs', 'emergency.triage.view-audit-logs'],
                ],
            ],
            'dependencies' => [],
            'limits' => [],
        ],

        'care_delivery' => [
            'label' => 'Care Delivery',
            'icon' => 'heart-pulse',
            'description' => 'Clinical encounters, orders, medical records, and clinical procedure orders.',
            'features' => [
                'clinical.encounters' => [
                    'label' => 'Clinical encounters',
                    'permissions' => ['medical.records.read', 'medical.records.create', 'medical.records.update'],
                ],
                'clinical.orders' => [
                    'label' => 'Clinical order entry',
                    'permissions' => ['laboratory.orders.create', 'pharmacy.orders.create', 'radiology.orders.create', 'theatre.procedures.create'],
                ],
                'clinical_procedure.orders' => [
                    'label' => 'Clinical procedure orders, worklist, and results',
                    'permissions' => ['clinical_procedure.orders.read', 'clinical_procedure.orders.create', 'clinical_procedure.orders.update', 'clinical_procedure.orders.update-status', 'clinical_procedure.orders.view-audit-logs'],
                ],
                'medical_records.core' => [
                    'label' => 'Medical records workspace',
                    'permissions' => ['medical.records.read', 'medical.records.create', 'medical.records.update'],
                ],
                'medical_records.governance' => [
                    'label' => 'Record finalization, attestation, archive, amendment, and audit',
                    'permissions' => ['medical.records.finalize', 'medical.records.attest', 'medical.records.archive', 'medical.records.amend', 'medical-records.view-audit-logs'],
                ],
            ],
            'dependencies' => [
                'clinical.orders' => ['clinical.encounters'],
                'clinical_procedure.orders' => ['clinical.encounters'],
                'medical_records.governance' => ['medical_records.core'],
            ],
            'limits' => [],
        ],

        'theatre' => [
            'label' => 'Theatre',
            'icon' => 'scissors',
            'description' => 'Operating theatre procedures and resource allocation.',
            'features' => [
                'theatre.procedures' => [
                    'label' => 'Theatre procedures and resource allocation',
                    'permissions' => ['theatre.procedures.read', 'theatre.procedures.create', 'theatre.procedures.update-status', 'theatre.procedures.manage-resources', 'theatre.procedures.view-resource-audit-logs', 'theatre.procedures.view-audit-logs'],
                ],
            ],
            'dependencies' => [
                'theatre.procedures' => ['clinical.encounters'],
            ],
            'limits' => [],
        ],

        'diagnostics' => [
            'label' => 'Diagnostics',
            'icon' => 'stethoscope',
            'description' => 'Laboratory and radiology order workflows.',
            'features' => [
                'laboratory.orders' => [
                    'label' => 'Laboratory orders, results, and verification',
                    'permissions' => ['laboratory.orders.read', 'laboratory.orders.create', 'laboratory.orders.update-status', 'laboratory.orders.verify-result', 'laboratory.orders.view-audit-logs', 'laboratory-orders.view-audit-logs'],
                ],
                'radiology.orders' => [
                    'label' => 'Radiology orders, worklist, and results',
                    'permissions' => ['radiology.orders.read', 'radiology.orders.create', 'radiology.orders.update', 'radiology.orders.update-status', 'radiology.orders.view-audit-logs', 'radiology-orders.view-audit-logs'],
                ],
            ],
            'dependencies' => [],
            'limits' => [],
        ],

        'pharmacy' => [
            'label' => 'Pharmacy',
            'icon' => 'pill',
            'description' => 'Pharmacy orders, dispensing, and reconciliation.',
            'features' => [
                'pharmacy.orders' => [
                    'label' => 'Pharmacy orders and dispensing workflow',
                    'permissions' => ['pharmacy.orders.read', 'pharmacy.orders.create', 'pharmacy.orders.update-status', 'pharmacy.orders.manage-policy', 'pharmacy.orders.reconcile', 'pharmacy.orders.verify-dispense', 'pharmacy.orders.view-audit-logs', 'pharmacy-orders.view-audit-logs'],
                ],
                'pharmacy.dispensing' => [
                    'label' => 'Pharmacy dispensing',
                    'permissions' => ['pharmacy.orders.create', 'pharmacy.orders.verify-dispense'],
                ],
            ],
            'dependencies' => [
                'pharmacy.dispensing' => ['pharmacy.orders'],
            ],
            'limits' => [],
        ],

        'ward_operations' => [
            'label' => 'Ward Operations',
            'icon' => 'clipboard-list',
            'description' => 'Inpatient ward census, nursing tasks, care plans, and discharge checklists.',
            'features' => [
                'inpatient.ward' => [
                    'label' => 'Inpatient ward census and nursing workspace',
                    'permissions' => ['inpatient.ward.read', 'inpatient.ward.view-audit-logs'],
                ],
                'inpatient.tasks' => [
                    'label' => 'Ward tasks and round notes',
                    'permissions' => ['inpatient.ward.create-task', 'inpatient.ward.update-task-status', 'inpatient.ward.create-round-note'],
                ],
                'inpatient.care_plans' => [
                    'label' => 'Care plans and discharge checklists',
                    'permissions' => ['inpatient.ward.create-care-plan', 'inpatient.ward.update-care-plan', 'inpatient.ward.update-care-plan-status', 'inpatient.ward.manage-discharge-checklist'],
                ],
            ],
            'dependencies' => [
                'inpatient.tasks' => ['inpatient.ward'],
                'inpatient.care_plans' => ['inpatient.ward'],
            ],
            'limits' => [],
        ],

        // Cashier Phase 6: rewritten around the prepaid ledger. The previous
        // definition sold invoicing, cash accounts, payment plans, corporate
        // billing and claims — every one of which was deleted in Phase 2, so
        // it described capabilities the product no longer has.
        'billing_revenue' => [
            'label' => 'Cashier & Revenue',
            'icon' => 'receipt',
            'description' => 'Prepaid service charges, cash payments, receipts, drawer sessions and daily reconciliation.',
            'features' => [
                'cashier.counter' => [
                    'label' => 'Cashier counter',
                    'permissions' => [
                        'cashier.access',
                        'cashier.charges.read',
                        'cashier.charges.create',
                        'cashier.charges.cancel',
                        'cashier.payments.record',
                        'cashier.payments.read',
                        'cashier.receipts.read',
                    ],
                ],
                'cashier.drawer' => [
                    'label' => 'Drawer sessions and reconciliation',
                    'permissions' => [
                        'cashier.sessions.read',
                        'cashier.sessions.open',
                        'cashier.sessions.close',
                        'cashier.sessions.move-cash',
                    ],
                ],
                'cashier.controls' => [
                    'label' => 'Financial controls',
                    'permissions' => [
                        'cashier.payments.reverse',
                        'cashier.receipts.reprint',
                        'cashier.refunds.request',
                        'cashier.refunds.approve',
                        'cashier.waivers.approve',
                        'cashier.sessions.approve-variance',
                    ],
                ],
                'cashier.reporting' => [
                    'label' => 'Revenue reporting',
                    'permissions' => ['cashier.reports.read'],
                ],
            ],
            'dependencies' => [
                'cashier.drawer' => ['cashier.counter'],
                'cashier.controls' => ['cashier.drawer'],
                'cashier.reporting' => ['cashier.counter'],
            ],
            'limits' => [
                'cashier.payments.monthly' => ['label' => 'Monthly cash transactions', 'unit' => 'transactions', 'default' => null],
            ],
        ],

        'fiscal_receipts' => [
            'label' => 'Fiscal Receipts',
            'icon' => 'file-check',
            'description' => 'TRA fiscal receipt readiness controls.',
            'features' => [
                'fiscal_receipts.tra' => [
                    'label' => 'TRA fiscal receipt readiness controls',
                    'permissions' => ['cashier.payments.record', 'cashier.receipts.read'],
                ],
            ],
            'dependencies' => [],
            'limits' => [],
        ],

        'inventory_supply' => [
            'label' => 'Stores & Supply',
            'icon' => 'package',
            'description' => 'Inventory item master, stock movements, requisitions, procurement, suppliers, warehouses, and transfers.',
            'features' => [
                'inventory.stock_issue' => [
                    'label' => 'Clinical stock issue',
                    'permissions' => ['inventory.procurement.read', 'inventory.procurement.create-request'],
                ],
                'inventory.items' => [
                    'label' => 'Inventory item master and stock catalog',
                    'permissions' => ['inventory.procurement.read', 'inventory.procurement.manage-items'],
                ],
                'inventory.stock_movements' => [
                    'label' => 'Stock movements and reconciliation',
                    'permissions' => ['inventory.procurement.create-movement', 'inventory.procurement.reconcile-stock'],
                ],
                'inventory.requisitions' => [
                    'label' => 'Department requisitions and approvals',
                    'permissions' => ['inventory.procurement.create-request', 'inventory.procurement.update-request-status'],
                ],
                'inventory.procurement' => [
                    'label' => 'Inventory and procurement',
                    'permissions' => ['inventory.procurement.read', 'inventory.procurement.manage-items', 'inventory.procurement.create-request'],
                ],
                'inventory.suppliers' => [
                    'label' => 'Supplier management',
                    'permissions' => ['inventory.procurement.manage-suppliers'],
                ],
                'inventory.warehouses' => [
                    'label' => 'Warehouse management',
                    'permissions' => ['inventory.procurement.manage-warehouses'],
                ],
                'inventory.transfers' => [
                    'label' => 'Warehouse transfers, pick slips, and dispatch notes',
                    'permissions' => ['inventory.procurement.read', 'inventory.procurement.create-movement'],
                ],
                'inventory.analytics' => [
                    'label' => 'Inventory analytics and audit logs',
                    'permissions' => ['inventory.procurement.view-audit-logs'],
                ],
            ],
            'dependencies' => [
                'inventory.stock_movements' => ['inventory.items'],
                'inventory.requisitions' => ['inventory.items'],
                'inventory.transfers' => ['inventory.items'],
                'inventory.analytics' => ['inventory.items'],
            ],
            'limits' => [
                'inventory.items.max' => ['label' => 'Active inventory items', 'unit' => 'items', 'default' => null],
            ],
        ],

        'people_credentialing' => [
            'label' => 'People & Credentialing',
            'icon' => 'users',
            'description' => 'Staff directory, profiles, documents, credentialing, and clinical privileges.',
            'features' => [
                'staff.directory' => [
                    'label' => 'Clinical staff directory',
                    'permissions' => ['staff.clinical-directory.read'],
                ],
                'staff.profiles' => [
                    'label' => 'Staff profiles and employment status',
                    'permissions' => ['staff.read', 'staff.create', 'staff.update', 'staff.update-status', 'staff.view-audit-logs'],
                ],
                'staff.documents' => [
                    'label' => 'Staff document management and verification',
                    'permissions' => ['staff.documents.read', 'staff.documents.create', 'staff.documents.update', 'staff.documents.verify', 'staff.documents.update-status', 'staff.documents.view-audit-logs'],
                ],
                'staff.credentialing' => [
                    'label' => 'Professional credentialing and registrations',
                    'permissions' => ['staff.credentialing.read', 'staff.credentialing.manage-profile', 'staff.credentialing.manage-registrations', 'staff.credentialing.verify', 'staff.credentialing.view-audit-logs'],
                ],
                'staff.privileges' => [
                    'label' => 'Clinical privileges and privilege catalogs',
                    'permissions' => ['staff.privileges.read', 'staff.privileges.create', 'staff.privileges.update', 'staff.privileges.review', 'staff.privileges.update-status', 'staff.privileges.approve', 'staff.privileges.view-audit-logs'],
                ],
            ],
            'dependencies' => [
                'staff.documents' => ['staff.profiles'],
                'staff.credentialing' => ['staff.profiles'],
                'staff.privileges' => ['staff.profiles'],
            ],
            'limits' => [
                'staff.seats' => ['label' => 'Active staff users', 'unit' => 'users', 'default' => null],
            ],
        ],

        'facility_setup' => [
            'label' => 'Facility Setup',
            'icon' => 'building-2',
            'description' => 'Departments, clinical specialties, facility administration, resource registry, and clinical catalog governance.',
            'features' => [
                'departments.management' => [
                    'label' => 'Department management',
                    'permissions' => ['departments.read', 'departments.create', 'departments.update', 'departments.update-status', 'departments.view-audit-logs'],
                ],
                'clinical.specialties' => [
                    'label' => 'Clinical specialties and staff specialty assignments',
                    'permissions' => ['specialties.read', 'specialties.create', 'specialties.update', 'specialties.update-status', 'specialties.view-audit-logs', 'staff.specialties.read', 'staff.specialties.manage'],
                ],
                'platform.facility_admin' => [
                    'label' => 'Facility administration',
                    'permissions' => ['platform.facilities.read', 'platform.facilities.create', 'platform.facilities.update', 'platform.facilities.update-status', 'platform.facilities.manage-owners', 'platform.facilities.view-audit-logs'],
                ],
                'platform.resource_registry' => [
                    'label' => 'Service points and ward bed registry',
                    'permissions' => ['platform.resources.read', 'platform.resources.manage-service-points', 'platform.resources.manage-ward-beds', 'platform.resources.view-audit-logs'],
                ],
                'platform.clinical_catalog' => [
                    'label' => 'Clinical catalog governance',
                    'permissions' => ['platform.clinical-catalog.read', 'platform.clinical-catalog.manage-lab-tests', 'platform.clinical-catalog.manage-radiology-procedures', 'platform.clinical-catalog.manage-theatre-procedures', 'platform.clinical-catalog.manage-clinical-procedures', 'platform.clinical-catalog.manage-formulary', 'platform.clinical-catalog.view-audit-logs'],
                ],
            ],
            'dependencies' => [
                'clinical.specialties' => ['staff.profiles'],
            ],
            'limits' => [],
        ],

        'platform_admin' => [
            'label' => 'Platform Administration',
            'icon' => 'shield-check',
            'description' => 'Users, RBAC, branding, feature flags, subscription administration, multi-facility operations, and rollout governance.',
            'features' => [
                'platform.user_security' => [
                    'label' => 'Users, access assignments, and approval cases',
                    'permissions' => ['platform.users.read', 'platform.users.create', 'platform.users.update', 'platform.users.update-status', 'platform.users.manage-facilities', 'platform.users.reset-password', 'platform.users.view-audit-logs', 'platform.users.approval-cases.read', 'platform.users.approval-cases.create', 'platform.users.approval-cases.manage', 'platform.users.approval-cases.review', 'platform.users.approval-cases.view-audit-logs'],
                ],
                'platform.rbac' => [
                    'label' => 'Roles, permissions, and RBAC audit',
                    'permissions' => ['platform.rbac.read', 'platform.rbac.manage-roles', 'platform.rbac.manage-user-roles', 'platform.rbac.view-audit-logs'],
                ],
                'platform.branding' => [
                    'label' => 'System branding and mail branding',
                    'permissions' => ['platform.settings.manage-branding'],
                ],
                'platform.feature_flags' => [
                    'label' => 'Feature flags and operational overrides',
                    'permissions' => ['platform.feature-flag-overrides.manage', 'platform.feature-flag-overrides.view-audit-logs'],
                ],
                'platform.subscription_admin' => [
                    'label' => 'Subscription plan and facility subscription administration',
                    'permissions' => ['platform.subscription-plans.read', 'platform.subscription-plans.manage', 'platform.subscription-plans.view-audit-logs', 'platform.facilities.manage-subscriptions'],
                ],
                'multi_facility.operations' => [
                    'label' => 'Multi-facility operations',
                    'permissions' => ['platform.multi-facility.read', 'platform.multi-facility.manage-rollouts', 'platform.multi-facility.view-audit-logs'],
                ],
                'facility.rollouts' => [
                    'label' => 'Rollout checkpoints, incidents, acceptance, and rollback',
                    'permissions' => ['platform.multi-facility.manage-rollouts', 'platform.multi-facility.manage-incidents', 'platform.multi-facility.execute-rollback', 'platform.multi-facility.approve-acceptance', 'platform.multi-facility.view-audit-logs'],
                ],
            ],
            'dependencies' => [
                'facility.rollouts' => ['multi_facility.operations'],
            ],
            'limits' => [],
        ],

        'governance_compliance' => [
            'label' => 'Governance & Compliance',
            'icon' => 'shield',
            'description' => 'Advanced audit, audit exports, retention evidence, and data privacy controls.',
            'features' => [
                'audit.advanced' => [
                    'label' => 'Advanced audit and export',
                    'permissions' => ['platform.cross-tenant.write', 'platform.cross-tenant.view-audit-logs', 'platform.rbac.view-audit-logs'],
                ],
                'audit.exports' => [
                    'label' => 'Audit export jobs, retry telemetry, and retention evidence',
                    'permissions' => ['platform.audit-export-jobs.cleanup', 'platform.audit-export-retry-resume-telemetry.cleanup', 'platform.cross-tenant-admin-audit-logs.retention-purge-scheduled', 'platform.cross-tenant.view-audit-logs'],
                ],
                'data_privacy.governance' => [
                    'label' => 'Data privacy and governance controls',
                    'permissions' => ['platform.cross-tenant.manage-audit-holds', 'platform.cross-tenant.view-audit-holds'],
                ],
            ],
            'dependencies' => [
                'audit.exports' => ['audit.advanced'],
                'data_privacy.governance' => ['audit.advanced'],
            ],
            'limits' => [],
        ],

        'interoperability' => [
            'label' => 'Interoperability',
            'icon' => 'plug',
            'description' => 'Integration adapters, payer integration, and national reporting readiness.',
            'features' => [
                'integrations.interoperability' => [
                    'label' => 'Integration adapters',
                    'permissions' => ['platform.cross-tenant.read'],
                ],
                'integrations.health_insurance' => [
                    'label' => 'Insurance and payer integration readiness',
                    'permissions' => ['patients.insurance.read', 'patients.insurance.manage'],
                ],
                'integrations.national_reporting' => [
                    'label' => 'National reporting interoperability readiness',
                    'permissions' => ['platform.cross-tenant.read'],
                ],
            ],
            'dependencies' => [
                'integrations.national_reporting' => ['reports.operational'],
            ],
            'limits' => [],
        ],

        'reporting' => [
            'label' => 'Reporting',
            'icon' => 'file-text',
            'description' => 'Daily cash, revenue cycle, operational, and executive reporting.',
            'features' => [
                'reports.daily_cash' => [
                    'label' => 'Daily cash reports',
                    'permissions' => ['cashier.reports.read', 'cashier.sessions.read'],
                ],
                'reports.revenue_cycle' => [
                    'label' => 'Revenue cycle reports',
                    'permissions' => ['cashier.reports.read'],
                ],
                'reports.operational' => [
                    'label' => 'Operational reports',
                    'permissions' => ['admissions.read', 'laboratory.orders.read', 'pharmacy.orders.read', 'inventory.procurement.read'],
                ],
                'reports.executive' => [
                    'label' => 'Executive reporting',
                    'permissions' => ['platform.cross-tenant.read', 'cashier.reports.read'],
                ],
            ],
            'dependencies' => [
                'reports.revenue_cycle' => ['cashier.reporting'],
                'reports.executive' => ['cashier.reporting'],
            ],
            'limits' => [],
        ],
    ],

    /*
    | Plan definitions. 'code' is the stable identifier seeded in
    | platform_subscription_plans.
    */
    'plans' => [
        'patient_registration' => [
            'name' => 'Clinic Starter',
            'description' => 'Small clinic or pilot facility foundation for patient access, appointments, basic cashiering, receipts, daily cash visibility, and local facility setup.',
            'price_amount' => '150000.00',
            'sort_order' => 10,
        ],
        'front_desk_billing' => [
            'name' => 'Front Office & Revenue',
            'description' => 'Private-clinic revenue desk package for registration, appointments, admissions intake, invoices, payments, POS, payer contracts, insurance claims, and TRA-ready receipt operations.',
            'price_amount' => '350000.00',
            'sort_order' => 20,
        ],
        'clinical_operations' => [
            'name' => 'Private Hospital Operations',
            'description' => 'Full single-facility hospital operations for OPD, emergency, admissions, medical records, lab, radiology, pharmacy, theatre, wards, inventory, staff governance, and operational reporting.',
            'price_amount' => '900000.00',
            'sort_order' => 30,
        ],
        'hospital_network' => [
            'name' => 'Enterprise Hospital Network',
            'description' => 'Advanced private hospital group package with all hospital operations plus multi-facility controls, rollout governance, integrations, advanced audit exports, executive reporting, and data-governance controls.',
            'price_amount' => '1800000.00',
            'sort_order' => 40,
        ],
    ],

    /*
    | Per-plan capability assignment. The plan grants every feature within the
    | listed capabilities (feature-level exclusions are supported in
    | 'plan_feature_exclusions' when a plan needs a partial capability).
    */
    'plan_capabilities' => [
        'patient_registration' => [
            'patient_access',
            'front_office',
            'billing_revenue',
            'point_of_sale',
            'fiscal_receipts',
            'people_credentialing',
            'facility_setup',
            'reporting',
        ],
        'front_desk_billing' => [
            'patient_access',
            'front_office',
            'billing_revenue',
            'point_of_sale',
            'fiscal_receipts',
            'people_credentialing',
            'facility_setup',
            'reporting',
        ],
        'clinical_operations' => [
            'patient_access',
            'front_office',
            'emergency_care',
            'care_delivery',
            'theatre',
            'diagnostics',
            'pharmacy',
            'ward_operations',
            'billing_revenue',
            'point_of_sale',
            'fiscal_receipts',
            'inventory_supply',
            'people_credentialing',
            'facility_setup',
            'reporting',
        ],
        'hospital_network' => [
            'patient_access',
            'front_office',
            'emergency_care',
            'care_delivery',
            'theatre',
            'diagnostics',
            'pharmacy',
            'ward_operations',
            'billing_revenue',
            'point_of_sale',
            'fiscal_receipts',
            'inventory_supply',
            'people_credentialing',
            'facility_setup',
            'platform_admin',
            'governance_compliance',
            'interoperability',
            'reporting',
        ],
    ],

    /*
    | Feature-level exclusions when a plan includes a capability but must not
    | grant a specific feature. Keys are entitlement keys.
    */
    'plan_feature_exclusions' => [
        'patient_registration' => [
            'appointments.provider_sessions',
            'appointments.referrals',
            'admissions.management',
            'staff.profiles',
            'staff.documents',
            'staff.credentialing',
            'staff.privileges',
            'platform.resource_registry',
            'platform.clinical_catalog',
            'reports.revenue_cycle',
            'reports.operational',
            'reports.executive',
        ],
        'front_desk_billing' => [
            'staff.documents',
            'staff.credentialing',
            'staff.privileges',
            'platform.clinical_catalog',
            'reports.operational',
            'reports.executive',
        ],
        'clinical_operations' => [
            'platform.user_security',
            'platform.rbac',
            'platform.branding',
            'platform.feature_flags',
            'platform.subscription_admin',
            'multi_facility.operations',
            'facility.rollouts',
            'audit.advanced',
            'audit.exports',
            'data_privacy.governance',
            'integrations.interoperability',
            'integrations.health_insurance',
            'integrations.national_reporting',
            'reports.executive',
        ],
        'hospital_network' => [],
    ],

    /*
    | Additive feature-level grants applied on top of capability assignments.
    | Used when a feature is intentionally enabled for every plan (e.g.
    | features rolled out platform-wide in a later migration).
    */
    'plan_features' => [
        'patient_registration' => [
            'clinical.walk_in_queue',
            'clinical_procedure.orders',
        ],
        'front_desk_billing' => [
            'clinical.walk_in_queue',
            'clinical_procedure.orders',
        ],
        'clinical_operations' => [
            'clinical.walk_in_queue',
            'clinical_procedure.orders',
        ],
        'hospital_network' => [
            'clinical.walk_in_queue',
            'clinical_procedure.orders',
        ],
    ],

    /*
    | Route → entitlement map used by EnsureMappedFacilitySubscriptionEntitlement.
    | Merges the middleware's legacy SPECIAL_ENTITLEMENT_MAP with the module
    | registry route_prefixes into a single source of truth. When this map is
    | non-empty the middleware uses it exclusively; otherwise it falls back to
    | the legacy hardcoded constants (safe during migration).
    */
    'route_entitlements' => [
        // Appointments
        'appointments.start-consultation' => ['appointments.provider_sessions'],
        'appointments.manage-provider-session' => ['appointments.provider_sessions'],
        'appointments.referrals.' => ['appointments.referrals'],
        'appointments.' => ['appointments.scheduling'],

        // Admissions (aggregate/reference signals use scheduling tier)
        'admissions.status-counts' => ['appointments.scheduling'],
        'admissions.discharge-destination-options' => ['appointments.scheduling'],
        'admissions.index' => ['appointments.scheduling'],
        'admissions.' => ['admissions.management'],

        // Medical records
        'medical-records.signer-attestations.' => ['medical_records.governance'],
        'medical-records.versions.' => ['medical_records.governance'],
        'medical-records.audit-logs.' => ['medical_records.governance'],
        'medical-records.audit-logs' => ['medical_records.governance'],
        'medical-records.' => ['medical_records.core'],

        // Encounters
        'encounters.audit-logs.' => ['medical_records.governance'],
        'encounters.audit-logs' => ['medical_records.governance'],
        'encounters.' => ['medical_records.core'],

        // Emergency & service requests
        'emergency-triage-cases.' => ['emergency.triage'],
        'service-requests.' => ['clinical.walk_in_queue'],

        // Inpatient ward
        'inpatient-ward.tasks.' => ['inpatient.tasks'],
        'inpatient-ward.round-notes.' => ['inpatient.tasks'],
        'inpatient-ward.care-plans.' => ['inpatient.care_plans'],
        'inpatient-ward.discharge-checklists.' => ['inpatient.care_plans'],
        'inpatient-ward.task-status-counts' => ['appointments.scheduling'],
        'inpatient-ward.care-plan-status-counts' => ['appointments.scheduling'],
        'inpatient-ward.discharge-checklist-status-counts' => ['appointments.scheduling'],
        'inpatient-ward.' => ['inpatient.ward'],

        // Billing

        // Point of sale

        // Inventory & procurement
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

        // Staff
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

        // Module registry route prefixes
        'clinical-procedure-orders.' => ['clinical_procedure.orders'],
        'laboratory-orders.' => ['laboratory.orders'],
        'pharmacy-orders.' => ['pharmacy.orders'],
        'radiology-orders.' => ['radiology.orders'],
        'emergency-triage.' => ['emergency.triage'],
        'emergency.' => ['emergency.triage'],
        'theatre-procedures.' => ['theatre.procedures'],
    ],

    /*
    | Per-plan quota overrides. Keys reference the limit keys declared on
    | capabilities. Absent keys fall back to the capability default (null =
    | unlimited). Enforced by FacilitySubscriptionAccessService (Phase 2).
    */
    'plan_limits' => [
        'patient_registration' => [
            'patients.monthly' => 1000,
            'staff.seats' => 5,
            'inventory.items.max' => 100,
        ],
        'front_desk_billing' => [
            'patients.monthly' => 5000,
            'staff.seats' => 20,
            'inventory.items.max' => 500,
        ],
        'clinical_operations' => [
            'patients.monthly' => 15000,
            'staff.seats' => 100,
            'inventory.items.max' => 5000,
            'cashier.payments.monthly' => 50000,
        ],
        'hospital_network' => [
            'patients.monthly' => null,
            'staff.seats' => null,
            'inventory.items.max' => null,
            'cashier.payments.monthly' => null,
        ],
    ],
];
