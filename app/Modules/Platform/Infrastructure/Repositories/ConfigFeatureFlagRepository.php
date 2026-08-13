<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\FeatureFlagRepositoryInterface;

class ConfigFeatureFlagRepository implements FeatureFlagRepositoryInterface
{
    public function all(): array
    {
        $flags = config('feature_flags.flags', []);

        return is_array($flags) ? $flags : [];
    }
}
