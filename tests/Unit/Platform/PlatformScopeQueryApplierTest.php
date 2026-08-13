<?php

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

it('applies facility scope before tenant scope', function (): void {
    $scopeContext = fakeScopeContext(
        tenantId: 'tenant-123',
        facilityId: 'facility-999',
    );

    $applier = new PlatformScopeQueryApplier($scopeContext);
    $query = DB::table('appointments');

    $applier->apply($query);

    expect($query->toSql())->toContain('facility_id');
    expect($query->toSql())->not->toContain('tenant_id');
    expect($query->getBindings())->toBe(['facility-999']);
});

it('falls back to tenant scope when facility scope is not resolved', function (): void {
    $scopeContext = fakeScopeContext(
        tenantId: 'tenant-123',
        facilityId: null,
    );

    $applier = new PlatformScopeQueryApplier($scopeContext);
    $query = DB::table('laboratory_orders');

    $applier->apply($query);

    expect($query->toSql())->toContain('tenant_id');
    expect($query->getBindings())->toBe(['tenant-123']);
});

it('leaves query unchanged when scope is unresolved and not required', function (): void {
    $scopeContext = fakeScopeContext(
        tenantId: null,
        facilityId: null,
    );

    $applier = new PlatformScopeQueryApplier($scopeContext);
    $query = DB::table('patients');

    $applier->apply($query);

    expect($query->toSql())->not->toContain('where');
    expect($query->getBindings())->toBe([]);
});

it('throws when scope is required but unresolved', function (): void {
    $scopeContext = fakeScopeContext(
        tenantId: null,
        facilityId: null,
    );

    $applier = new PlatformScopeQueryApplier($scopeContext);

    expect(fn () => $applier->apply(DB::table('billing_invoices'), requireResolvedScope: true))
        ->toThrow(RuntimeException::class, 'Platform scope is required but unresolved.');
});

it('throws when facility scope is specifically required and missing', function (): void {
    $scopeContext = fakeScopeContext(
        tenantId: 'tenant-123',
        facilityId: null,
    );

    $applier = new PlatformScopeQueryApplier($scopeContext);

    expect(fn () => $applier->requireFacility(DB::table('pharmacy_orders')))
        ->toThrow(RuntimeException::class, 'Facility scope is required but unresolved.');
});

/**
 * @return CurrentPlatformScopeContextInterface
 */
function fakeScopeContext(?string $tenantId, ?string $facilityId): CurrentPlatformScopeContextInterface
{
    return new class($tenantId, $facilityId) implements CurrentPlatformScopeContextInterface
    {
        public function __construct(
            private readonly ?string $tenantIdValue,
            private readonly ?string $facilityIdValue,
        ) {}

        public function toArray(): array
        {
            return [
                'tenant' => $this->tenantIdValue ? ['id' => $this->tenantIdValue, 'code' => 'TEN'] : null,
                'facility' => $this->facilityIdValue ? ['id' => $this->facilityIdValue, 'code' => 'FAC'] : null,
                'resolvedFrom' => $this->facilityIdValue ? 'headers' : ($this->tenantIdValue ? 'tenant_header' : 'none'),
                'headers' => ['tenantCode' => null, 'facilityCode' => null],
                'userAccess' => ['accessibleFacilityCount' => 0, 'facilities' => []],
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
            return $this->tenantIdValue;
        }

        public function facilityId(): ?string
        {
            return $this->facilityIdValue;
        }

        public function resolvedFrom(): string
        {
            return $this->toArray()['resolvedFrom'];
        }

        public function hasTenant(): bool
        {
            return $this->tenantIdValue !== null;
        }

        public function hasFacility(): bool
        {
            return $this->facilityIdValue !== null;
        }
    };
}
