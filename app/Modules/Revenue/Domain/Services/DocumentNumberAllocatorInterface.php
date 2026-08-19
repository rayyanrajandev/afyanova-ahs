<?php

namespace App\Modules\Revenue\Domain\Services;

interface DocumentNumberAllocatorInterface
{
    /**
     * Allocate the next number in a facility's book for the given document
     * type, e.g. "RCP-2026-000417".
     *
     * Must be called inside a transaction that also writes the document. If
     * that transaction rolls back the number is released with it, which is the
     * only way to keep the sequence gapless — an allocator that commits
     * independently leaves a hole every time a payment fails.
     */
    public function allocate(
        string $documentType,
        ?string $tenantId,
        ?string $facilityId,
    ): string;
}
