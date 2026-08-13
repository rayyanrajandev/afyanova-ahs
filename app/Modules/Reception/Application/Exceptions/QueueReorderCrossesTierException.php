<?php

namespace App\Modules\Reception\Application\Exceptions;

use RuntimeException;

/**
 * Volume 2.1 §10.3 "Reorder" / Volume 3.7 T5.5 — tier (emergency > scheduled
 * > walk-in) is a hard floor, not a suggestion a drag can override. Thrown
 * when a submitted reorder would place a lower-priority tier's appointment
 * ahead of a higher-priority tier's — e.g. a walk-in dragged above an
 * emergency arrival. Reordering *within* a tier is always allowed.
 */
class QueueReorderCrossesTierException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'This order would move a patient ahead of a higher-priority arrival (emergency/scheduled). '
            .'You can reorder within the same priority group, but not across it.',
        );
    }
}
