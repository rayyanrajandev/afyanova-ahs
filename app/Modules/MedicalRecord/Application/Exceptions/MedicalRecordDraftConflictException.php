<?php

namespace App\Modules\MedicalRecord\Application\Exceptions;

use RuntimeException;

class MedicalRecordDraftConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $currentRecord
     */
    public function __construct(
        private readonly array $currentRecord,
        string $message = 'This draft was updated elsewhere. Reload the chart copy or overwrite with your changes.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function currentRecord(): array
    {
        return $this->currentRecord;
    }
}
