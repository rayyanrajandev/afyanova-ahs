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
        'laboratory_order' => false,
        'radiology_order' => false,
        'clinical_procedure_order' => false,
        'pharmacy_order' => false,
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
