<?php

return [
    'headers' => [
        'tenant' => 'X-Tenant-Code',
        'facility' => 'X-Facility-Code',
    ],

    'cookies' => [
        'tenant' => 'platform_tenant_code',
        'facility' => 'platform_facility_code',
    ],

    'auto_select_single_facility' => true,
];
