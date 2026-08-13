<?php

namespace App\Modules\Platform\Domain\Services;

interface FeatureFlagResolverInterface
{
    public function isEnabled(string $flagName, bool $default = false): bool;
}
