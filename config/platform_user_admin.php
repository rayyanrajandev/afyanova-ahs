<?php

return [
    'privileged_change_controls' => [
        'enabled' => (bool) env('PLATFORM_USER_ADMIN_PRIVILEGED_APPROVAL_REQUIRED', true),
        'permission_prefixes' => [
            'platform.cross-tenant.',
        ],
        'permission_names' => [
            'platform.rbac.manage-roles',
            'platform.rbac.manage-user-roles',
            'platform.users.update-status',
            'platform.users.manage-facilities',
            'platform.users.reset-password',
            'platform.feature-flag-overrides.manage',
        ],
        'approval_case_reference' => [
            'pattern' => '/^[A-Za-z0-9][A-Za-z0-9\\-_\\/.:]{5,119}$/',
        ],
    ],
];
