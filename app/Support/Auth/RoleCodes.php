<?php

namespace App\Support\Auth;

/**
 * Centralized role-code registry.
 *
 * Single source of truth for role codes used across policies and
 * authorization helpers. Keeps hardcoded role codes from drifting
 * from config/roles.php (see RBAC audit §5.2).
 */
final class RoleCodes
{
    public const PLATFORM_SUPER_ADMIN = 'PLATFORM.SUPER.ADMIN';
    public const SYSTEM_SUPER_ADMIN = 'SYSTEM.SUPER.ADMIN';
    public const PLATFORM_USER_ADMIN = 'PLATFORM.USER.ADMIN';
    public const PLATFORM_RBAC_ADMIN = 'PLATFORM.RBAC.ADMIN';
    public const PLATFORM_SUBSCRIPTION_ADMIN = 'PLATFORM.SUBSCRIPTION.ADMIN';

    public const CLINICAL_GENERAL = 'CLINICAL.GENERAL';
    public const CLINICAL_PHYSICIAN = 'CLINICAL.PHYSICIAN';
    public const CLINICAL_SURGEON = 'CLINICAL.SURGEON';
    public const CLINICAL_NURSE = 'CLINICAL.NURSE';
    public const CLINICAL_NURSE_MIDWIFE = 'CLINICAL.NURSE.MIDWIFE';
    public const CLINICAL_DENTAL_OFFICER = 'CLINICAL.DENTAL.OFFICER';
    public const CLINICAL_EMERGENCY = 'CLINICAL.EMERGENCY';

    public const LAB_STAFF = 'LAB.STAFF';
    public const LAB_SUPERVISOR = 'LAB.SUPERVISOR';
    public const LAB_MANAGER = 'LAB.MANAGER';

    public const PHARMACY_STAFF = 'PHARMACY.STAFF';
    public const PHARMACY_SUPERVISOR = 'PHARMACY.SUPERVISOR';
    public const PHARMACY_MANAGER = 'PHARMACY.MANAGER';

    public const RADIOLOGY_STAFF = 'RADIOLOGY.STAFF';
    public const RADIOLOGY_SUPERVISOR = 'RADIOLOGY.SUPERVISOR';
    public const RADIOLOGY_MANAGER = 'RADIOLOGY.MANAGER';

    public const FINANCE_CASHIER = 'FINANCE.CASHIER';
    public const FINANCE_OFFICER = 'FINANCE.OFFICER';
    public const FINANCE_CONTROLLER = 'FINANCE.CONTROLLER';
    public const FINANCE_CLAIMS = 'FINANCE.CLAIMS';

    public const ADMIN_FACILITY = 'ADMIN.FACILITY';
    public const ADMIN_REGISTRATION = 'ADMIN.REGISTRATION';
    public const ADMIN_MEDICAL_RECORDS = 'ADMIN.MEDICAL.RECORDS';

    public const INVENTORY_STAFF = 'INVENTORY.STAFF';
    public const INVENTORY_SUPERVISOR = 'INVENTORY.SUPERVISOR';
    public const INVENTORY_MANAGER = 'INVENTORY.MANAGER';

    public const THEATRE_STAFF = 'THEATRE.STAFF';
    public const THEATRE_SUPERVISOR = 'THEATRE.SUPERVISOR';
    public const THEATRE_MANAGER = 'THEATRE.MANAGER';

    public const ALLIED_NUTRITIONIST = 'ALLIED.NUTRITIONIST';
    public const ALLIED_COUNSELOR = 'ALLIED.COUNSELOR';
    public const ALLIED_COMMUNITY_HEALTH_WORKER = 'ALLIED.COMMUNITY.HEALTH.WORKER';

    public const SUPPORT_MEDICAL_ATTENDANT = 'SUPPORT.MEDICAL.ATTENDANT';
    public const SUPPORT_HEALTH_SECRETARY = 'SUPPORT.HEALTH.SECRETARY';

    /**
     * Clinical provider role codes (can act as consultation providers).
     *
     * @var array<int, string>
     */
    public const CLINICAL_PROVIDER_ROLES = [
        self::CLINICAL_PHYSICIAN,
        self::CLINICAL_GENERAL,
    ];

    /**
     * Clinical role codes that can update patient demographics.
     *
     * @var array<int, string>
     */
    public const CLINICAL_ROLES = [
        self::CLINICAL_PHYSICIAN,
        self::CLINICAL_GENERAL,
        self::CLINICAL_EMERGENCY,
        self::CLINICAL_NURSE,
        self::CLINICAL_NURSE_MIDWIFE,
        self::CLINICAL_DENTAL_OFFICER,
        self::CLINICAL_SURGEON,
    ];

    /**
     * Role codes explicitly excluded from consultation-provider resolution.
     *
     * @var array<int, string>
     */
    public const EXCLUDED_PROVIDER_ROLES = [
        self::CLINICAL_NURSE,
        self::LAB_STAFF,
        self::LAB_SUPERVISOR,
        self::LAB_MANAGER,
        self::RADIOLOGY_STAFF,
        self::RADIOLOGY_SUPERVISOR,
        self::RADIOLOGY_MANAGER,
        self::PHARMACY_STAFF,
        self::PHARMACY_SUPERVISOR,
        self::PHARMACY_MANAGER,
        self::ADMIN_REGISTRATION,
        self::ADMIN_MEDICAL_RECORDS,
        self::FINANCE_CASHIER,
        self::FINANCE_OFFICER,
    ];
}