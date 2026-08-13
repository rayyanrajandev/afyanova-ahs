<?php

namespace App\Modules\Platform\Application\Exceptions;

use RuntimeException;

class TenantScopeRequiredForIsolationException extends RuntimeException
{
    public function __construct(string $message = 'Tenant scope is required when multi-tenant isolation is enabled.')
    {
        parent::__construct($message);
    }
}
