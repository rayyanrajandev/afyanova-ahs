<?php

namespace App\Concerns;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;

trait TenantScoped
{
    /**
     * Boot the tenant scoping trait.
     *
     * Automatically scopes all queries to the current tenant
     * and sets tenant_id on creation.
     */
    public static function bootTenantScoped(): void
    {
        static::addGlobalScope('tenant', function ($builder) {
            $context = app(CurrentPlatformScopeContextInterface::class);

            if ($context->hasTenant()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $context->tenantId());
            }
        });

        static::creating(function ($model) {
            if ($model->tenant_id === null) {
                $context = app(CurrentPlatformScopeContextInterface::class);
                if ($context->hasTenant()) {
                    $model->tenant_id = $context->tenantId();
                }
            }
        });

        static::saving(function ($model) {
            if ($model->isDirty('tenant_id') && $model->getOriginal('tenant_id') !== null) {
                throw new \RuntimeException('Cannot change tenant_id on an existing record.');
            }
        });
    }

    /**
     * Query scope to find records for a specific tenant, bypassing the global scope.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $tenantId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->withoutGlobalScope('tenant')
            ->where($this->getTable().'.tenant_id', $tenantId);
    }
}