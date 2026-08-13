<?php

use App\Modules\Platform\Domain\Repositories\CountryProfileRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FeatureFlagRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Infrastructure\Services\RequestScopedFeatureFlagResolver;
use Tests\TestCase;

uses(TestCase::class);

it('applies effective feature flag precedence from country to tenant to facility', function (): void {
    $featureFlags = new class implements FeatureFlagRepositoryInterface
    {
        public function all(): array
        {
            return [
                'platform.multi_facility_scoping' => [
                    'enabled' => false,
                    'owner' => 'platform',
                ],
            ];
        }
    };

    $overrides = new class implements FeatureFlagOverrideRepositoryInterface
    {
        public function list(array $filters = []): array
        {
            return [];
        }

        public function listApplicable(array $flagNames, array $scopes): array
        {
            return [
                ['scope_type' => 'country', 'scope_key' => 'TZ', 'enabled' => true],
                ['scope_type' => 'tenant', 'scope_key' => 'tenant-1', 'enabled' => false],
                ['scope_type' => 'facility', 'scope_key' => 'facility-1', 'enabled' => true],
            ];
        }

        public function findById(string $id): ?array
        {
            return null;
        }

        public function findByIdentity(string $flagName, string $scopeType, string $scopeKey): ?array
        {
            return null;
        }

        public function create(array $payload): array
        {
            return $payload;
        }

        public function updateById(string $id, array $payload): ?array
        {
            return null;
        }

        public function deleteById(string $id): bool
        {
            return false;
        }
    };

    $countryProfiles = new class implements CountryProfileRepositoryInterface
    {
        public function getActiveCode(): string
        {
            return 'TZ';
        }

        public function findByCode(string $code): ?array
        {
            return null;
        }

        public function all(): array
        {
            return [];
        }
    };

    $scopeContext = new class implements CurrentPlatformScopeContextInterface
    {
        public function toArray(): array
        {
            return [
                'tenant' => ['id' => 'tenant-1', 'code' => 'TEN', 'countryCode' => 'TZ'],
                'facility' => ['id' => 'facility-1', 'code' => 'FAC'],
                'resolvedFrom' => 'headers',
                'headers' => ['tenantCode' => 'TEN', 'facilityCode' => 'FAC'],
                'userAccess' => ['accessibleFacilityCount' => 1, 'facilities' => []],
            ];
        }

        public function tenant(): ?array
        {
            return $this->toArray()['tenant'];
        }

        public function facility(): ?array
        {
            return $this->toArray()['facility'];
        }

        public function tenantId(): ?string
        {
            return 'tenant-1';
        }

        public function facilityId(): ?string
        {
            return 'facility-1';
        }

        public function resolvedFrom(): string
        {
            return 'headers';
        }

        public function hasTenant(): bool
        {
            return true;
        }

        public function hasFacility(): bool
        {
            return true;
        }
    };

    $resolver = new RequestScopedFeatureFlagResolver(
        $featureFlags,
        $overrides,
        $countryProfiles,
        $scopeContext,
    );

    expect($resolver->isEnabled('platform.multi_facility_scoping'))->toBeTrue();
});

it('returns provided default for unknown feature flag', function (): void {
    $resolver = new RequestScopedFeatureFlagResolver(
        new class implements FeatureFlagRepositoryInterface
        {
            public function all(): array
            {
                return [];
            }
        },
        new class implements FeatureFlagOverrideRepositoryInterface
        {
            public function list(array $filters = []): array { return []; }
            public function listApplicable(array $flagNames, array $scopes): array { return []; }
            public function findById(string $id): ?array { return null; }
            public function findByIdentity(string $flagName, string $scopeType, string $scopeKey): ?array { return null; }
            public function create(array $payload): array { return $payload; }
            public function updateById(string $id, array $payload): ?array { return null; }
            public function deleteById(string $id): bool { return false; }
        },
        new class implements CountryProfileRepositoryInterface
        {
            public function getActiveCode(): string { return 'TZ'; }
            public function findByCode(string $code): ?array { return null; }
            public function all(): array { return []; }
        },
        new class implements CurrentPlatformScopeContextInterface
        {
            public function toArray(): array { return ['tenant' => null, 'facility' => null, 'resolvedFrom' => 'none']; }
            public function tenant(): ?array { return null; }
            public function facility(): ?array { return null; }
            public function tenantId(): ?string { return null; }
            public function facilityId(): ?string { return null; }
            public function resolvedFrom(): string { return 'none'; }
            public function hasTenant(): bool { return false; }
            public function hasFacility(): bool { return false; }
        },
    );

    expect($resolver->isEnabled('platform.unknown_flag', true))->toBeTrue();
    expect($resolver->isEnabled('platform.unknown_flag', false))->toBeFalse();
});
