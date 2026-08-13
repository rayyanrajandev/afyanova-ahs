<?php

namespace App\Modules\Billing\Domain\ValueObjects;

class NhifClaimSubmissionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $claimReference = null,
        public readonly ?string $submissionStatus = null,
        public readonly ?string $message = null,
        public readonly ?array $rawPayload = null,
        public readonly ?array $rawResponse = null,
        public readonly ?string $errorCode = null,
    ) {}
}
