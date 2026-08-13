<?php

namespace App\Modules\Platform\Domain\Repositories;

interface FeatureFlagRepositoryInterface
{
    public function all(): array;
}
