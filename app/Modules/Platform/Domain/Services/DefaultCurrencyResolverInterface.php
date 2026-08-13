<?php

namespace App\Modules\Platform\Domain\Services;

interface DefaultCurrencyResolverInterface
{
    public function resolve(): string;
}
