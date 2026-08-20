<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Consultation charging
    |--------------------------------------------------------------------------
    |
    | Consultation is the first service under the prepaid rule: it is paid for
    | before the patient reaches a clinician. These codes tell the appointment
    | trigger which catalogue item to price a visit against.
    |
    | The codes are looked up in chargeable_items. A facility that renames or
    | re-tiers its consultation items changes them here rather than in code.
    */
    'consultation' => [
        'default_item_code' => env('REVENUE_CONSULTATION_ITEM_CODE', 'CONSULT-GENERAL-OPD'),

        // Resolved by the clinician's tier where one is known; falls back to
        // default_item_code above.
        'item_codes_by_tier' => [
            'general' => 'CONSULT-GENERAL-OPD',
            'specialist' => 'CONSULT-SPECIALIST-OPD',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prepaid gate
    |--------------------------------------------------------------------------
    |
    | Which services may not be provided before they are paid for. Only
    | consultation is enforced in this phase; the other kinds are listed so the
    | switch is visible, and turning one on is a configuration change plus the
    | trigger that raises its charge.
    */
    'prepaid_required_for' => [
        'consultation' => env('REVENUE_PREPAID_CONSULTATION', true),
        'laboratory_order' => env('REVENUE_PREPAID_LABORATORY_ORDER', true),
        'radiology_order' => env('REVENUE_PREPAID_RADIOLOGY_ORDER', true),
        'clinical_procedure_order' => env('REVENUE_PREPAID_CLINICAL_PROCEDURE_ORDER', true),
        'pharmacy_order' => env('REVENUE_PREPAID_PHARMACY_ORDER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Counter-raised charges
    |--------------------------------------------------------------------------
    |
    | What a cashier may add by hand.
    |
    | A charge for a clinically ordered service must come from the order, never
    | from the counter. The prescriber decides that a patient needs 21 tablets
    | and the pharmacist decides what is actually dispensed; a cashier has no
    | basis for either number, and letting them type one produces a charge that
    | matches no prescription.
    |
    | So these catalogue types are excluded from the ad-hoc charge search. Their
    | charges are raised by the workspace that owns the order — consultation
    | already is, at appointment creation; laboratory, imaging, procedures and
    | pharmacy follow when each workspace's trigger is built.
    |
    | A facility that genuinely sells one of these over the counter — a walk-in
    | dressing change, say — can remove that type here. That is a deliberate
    | decision, not a default.
    */
    'counter_charge_excluded_catalog_types' => [
        'formulary_item',
        'lab_test',
        'radiology_procedure',
        'clinical_procedure',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cash controls
    |--------------------------------------------------------------------------
    |
    | How far a counted drawer may differ from the ledger before a second
    | person has to sign it off. Zero means every discrepancy escalates, which
    | is the right default for a currency with no circulating subunit: in TZS
    | an exact count is achievable, so any difference is worth a look.
    */
    'cash_variance_tolerance_minor' => (int) env('REVENUE_CASH_VARIANCE_TOLERANCE_MINOR', 0),

];
