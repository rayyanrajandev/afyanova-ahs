<?php

/**
 * Consultation Classification Policy Configuration
 *
 * Controls how OPD visits are classified as NEW vs REVIEW consultations
 * and how billing is adjusted for review visits.
 *
 * All values can be overridden at facility level via SystemSetting records.
 * See system_settings_keys below for the exact keys to use.
 *
 * Environment variables (optional, applied when no SystemSetting override exists):
 *   CONSULTATION_FOLLOW_UP_DAYS          - integer, default 14
 *   CONSULTATION_REVIEW_FEE_PERCENTAGE   - float 0–100, default 50.0
 *   CONSULTATION_REVIEW_FEE_IS_FREE      - boolean, default false
 *   CONSULTATION_SAME_COMPLAINT_REQUIRED - boolean, default true
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Follow-up Window (days)
    |--------------------------------------------------------------------------
    | If a patient returns within this many days of their last completed
    | appointment at the same facility, they are eligible for a REVIEW
    | classification. Set to 0 to disable automatic REVIEW classification.
    */
    'follow_up_days' => (int) env('CONSULTATION_FOLLOW_UP_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Review Fee Percentage
    |--------------------------------------------------------------------------
    | The percentage of the standard consultation fee charged for a REVIEW visit.
    | 50 means the patient pays 50% of the normal consultation fee.
    | 0 and review_fee_is_free=false still charges 0% (effectively free).
    | This value is ignored when review_fee_is_free is true.
    */
    'review_fee_percentage' => (float) env('CONSULTATION_REVIEW_FEE_PERCENTAGE', 50.0),

    /*
    |--------------------------------------------------------------------------
    | Free Review
    |--------------------------------------------------------------------------
    | When true, REVIEW consultations have zero consultation fee regardless of
    | the review_fee_percentage setting. All other charges (labs, drugs) apply
    | normally; only the consultation line item is waived.
    */
    'review_fee_is_free' => (bool) env('CONSULTATION_REVIEW_FEE_IS_FREE', false),

    /*
    |--------------------------------------------------------------------------
    | Same Complaint Required
    |--------------------------------------------------------------------------
    | When true, the system only classifies a return visit as REVIEW if the
    | appointment reason contains at least one matching word from the prior
    | appointment's reason. When false, any return within the follow-up window
    | is classified as REVIEW regardless of stated complaint.
    */
    'same_complaint_required' => (bool) env('CONSULTATION_SAME_COMPLAINT_REQUIRED', true),

    /*
    |--------------------------------------------------------------------------
    | System Settings Keys (Facility-Level Overrides)
    |--------------------------------------------------------------------------
    | When a SystemSetting record exists with the key below, its value takes
    | precedence over the values above for the active facility.
    | Type mapping:  follow_up_days => integer
    |                review_fee_percentage => decimal
    |                review_fee_is_free => boolean
    |                same_complaint_required => boolean
    */
    'system_settings_keys' => [
        'follow_up_days'          => 'consultation.follow_up_days',
        'review_fee_percentage'   => 'consultation.review_fee_percentage',
        'review_fee_is_free'      => 'consultation.review_fee_is_free',
        'same_complaint_required' => 'consultation.same_complaint_required',
    ],

    /*
    |--------------------------------------------------------------------------
    | Consultation Service Type Tag
    |--------------------------------------------------------------------------
    | The service_type value used on billing_service_catalog_items that represent
    | a consultation charge. When applying a review discount, only line items
    | whose resolved catalog item has this service_type receive the discount.
    */
    'consultation_service_type' => env('CONSULTATION_SERVICE_TYPE', 'consultation'),

];
