<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway (Selcom)
    |--------------------------------------------------------------------------
    */
    'payment_gateway' => [
        'driver' => env('BILLING_PAYMENT_GATEWAY', 'selcom'),

        'selcom' => [
            'base_url' => env('SELCOM_BASE_URL', 'https://api.selcommobile.com'),
            'api_key' => env('SELCOM_API_KEY', ''),
            'api_secret' => env('SELCOM_API_SECRET', ''),
            'vendor' => env('SELCOM_VENDOR', ''),
            'pin' => env('SELCOM_PIN', ''),
            'currency' => env('SELCOM_CURRENCY', 'TZS'),
            'timeout' => env('SELCOM_TIMEOUT', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | NHIF API
    |--------------------------------------------------------------------------
    */
    'nhif' => [
        'base_url' => env('NHIF_API_BASE_URL', 'https://api.nhif.or.tz'),
        'client_id' => env('NHIF_CLIENT_ID', ''),
        'client_secret' => env('NHIF_CLIENT_SECRET', ''),
        'scope' => env('NHIF_API_SCOPE', 'OMRS'),
        'timeout' => env('NHIF_API_TIMEOUT', 15),
        'facility_code' => env('NHIF_FACILITY_CODE', ''),
        'auto_verify_on_checkin' => env('NHIF_AUTO_VERIFY_ON_CHECKIN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | TRA/VFD (TotalVFD)
    |--------------------------------------------------------------------------
    */
    'tra_vfd' => [
        'provider' => env('TRA_VFD_PROVIDER', 'totalvfd'),

        'totalvfd' => [
            'base_url' => env('TOTALVFD_BASE_URL', 'https://testapi.totalvfd.co.tz'),
            'api_key' => env('TOTALVFD_API_KEY', ''),
            'api_secret' => env('TOTALVFD_API_SECRET', ''),
            'tin' => env('TOTALVFD_TIN', ''),
            'vrn' => env('TOTALVFD_VRN', ''),
            'business_name' => env('TOTALVFD_BUSINESS_NAME', ''),
            'business_street' => env('TOTALVFD_BUSINESS_STREET', ''),
            'business_city' => env('TOTALVFD_BUSINESS_CITY', 'Dar Es Salaam'),
            'business_mobile' => env('TOTALVFD_BUSINESS_MOBILE', ''),
            'efd_serial' => env('TOTALVFD_EFD_SERIAL', ''),
            'tax_office' => env('TOTALVFD_TAX_OFFICE', ''),
            'currency' => env('TOTALVFD_CURRENCY', 'TZS'),
            'timeout' => env('TOTALVFD_TIMEOUT', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS Provider (Africa's Talking)
    |--------------------------------------------------------------------------
    */
    'sms' => [
        'driver' => env('BILLING_SMS_PROVIDER', 'africastalking'),

        'africastalking' => [
            'username' => env('AFRICASTALKING_USERNAME', ''),
            'api_key' => env('AFRICASTALKING_API_KEY', ''),
            'from' => env('AFRICASTALKING_FROM', ''),
            'base_url' => env('AFRICASTALKING_BASE_URL', 'https://api.africastalking.com'),
        ],
    ],
];
