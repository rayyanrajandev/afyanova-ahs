<?php

namespace App\Modules\ServiceRequest\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when a Direct Service item references a catalog item that hasn't
 * been flagged direct_service_eligible — server-side enforcement of the
 * whitelist, not just a UI restriction (the picker only offers eligible
 * items, but this is what actually stops the request if that's bypassed).
 */
class CatalogItemNotEligibleForDirectServiceException extends RuntimeException
{
}
