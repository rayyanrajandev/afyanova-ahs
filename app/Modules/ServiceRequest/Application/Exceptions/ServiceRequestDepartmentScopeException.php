<?php

namespace App\Modules\ServiceRequest\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when a department-scoped actor (see ServiceRequestDepartmentScope)
 * attempts to act on a service request outside their own department, or has
 * no department assigned at all.
 */
class ServiceRequestDepartmentScopeException extends RuntimeException {}
