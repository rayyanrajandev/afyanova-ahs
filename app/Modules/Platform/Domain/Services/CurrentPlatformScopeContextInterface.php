<?php

namespace App\Modules\Platform\Domain\Services;

interface CurrentPlatformScopeContextInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * @return array<string, mixed>|null
     */
    public function tenant(): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function facility(): ?array;

    public function tenantId(): ?string;

    public function facilityId(): ?string;

    public function resolvedFrom(): string;

    public function hasTenant(): bool;

    public function hasFacility(): bool;
}
