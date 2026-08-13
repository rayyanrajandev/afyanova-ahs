<?php

use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Services\TenantIsolationWriteGuard;

it('does nothing when multi tenant isolation flag is disabled', function (): void {
    $guard = new TenantIsolationWriteGuard(
        featureFlagResolver: fakeFeatureFlagResolver(false),
        platformScopeContext: fakeTenantScopeContext(null),
    );

    $guard->assertTenantScopeForWrite();

    expect(true)->toBeTrue();
});

it('does nothing when tenant scope is resolved', function (): void {
    $guard = new TenantIsolationWriteGuard(
        featureFlagResolver: fakeFeatureFlagResolver(true),
        platformScopeContext: fakeTenantScopeContext('tenant-123'),
    );

    $guard->assertTenantScopeForWrite();

    expect(true)->toBeTrue();
});

it('throws when multi tenant isolation is enabled and tenant scope is unresolved', function (): void {
    $guard = new TenantIsolationWriteGuard(
        featureFlagResolver: fakeFeatureFlagResolver(true),
        platformScopeContext: fakeTenantScopeContext(null),
    );

    expect(fn () => $guard->assertTenantScopeForWrite())
        ->toThrow(TenantScopeRequiredForIsolationException::class, 'Tenant scope is required');
});

function fakeFeatureFlagResolver(bool $enabled): FeatureFlagResolverInterface
{
    return new class($enabled) implements FeatureFlagResolverInterface
    {
        public function __construct(private readonly bool $enabledValue) {}

        public function isEnabled(string $flagName, bool $default = false): bool
        {
            if ($flagName !== 'platform.multi_tenant_isolation') {
                return $default;
            }

            return $this->enabledValue;
        }
    };
}

function fakeTenantScopeContext(?string $tenantId): CurrentPlatformScopeContextInterface
{
    return new class($tenantId) implements CurrentPlatformScopeContextInterface
    {
        public function __construct(private readonly ?string $tenantIdValue) {}

        public function toArray(): array
        {
            return [
                'tenant' => $this->tenantIdValue ? ['id' => $this->tenantIdValue] : null,
                'facility' => null,
                'resolvedFrom' => $this->tenantIdValue ? 'headers' : 'none',
            ];
        }

        public function tenant(): ?array
        {
            return $this->toArray()['tenant'];
        }

        public function facility(): ?array
        {
            return null;
        }

        public function tenantId(): ?string
        {
            return $this->tenantIdValue;
        }

        public function facilityId(): ?string
        {
            return null;
        }

        public function resolvedFrom(): string
        {
            return $this->tenantIdValue ? 'headers' : 'none';
        }

        public function hasTenant(): bool
        {
            return $this->tenantIdValue !== null;
        }

        public function hasFacility(): bool
        {
            return false;
        }
    };
}
