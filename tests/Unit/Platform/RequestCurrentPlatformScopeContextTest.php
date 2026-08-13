<?php

use App\Modules\Platform\Infrastructure\Services\RequestCurrentPlatformScopeContext;
use Illuminate\Http\Request;

it('returns default scope shape when request attribute is missing', function (): void {
    $request = Request::create('/api/v1/platform/access-scope', 'GET');
    $context = new RequestCurrentPlatformScopeContext($request);

    expect($context->toArray()['resolvedFrom'])->toBe('none');
    expect($context->tenant())->toBeNull();
    expect($context->facility())->toBeNull();
    expect($context->tenantId())->toBeNull();
    expect($context->facilityId())->toBeNull();
    expect($context->hasTenant())->toBeFalse();
    expect($context->hasFacility())->toBeFalse();
});

it('reads normalized tenant and facility values from request platform scope attribute', function (): void {
    $request = Request::create('/api/v1/platform/access-scope', 'GET');
    $request->attributes->set('platform.scope', [
        'tenant' => [
            'id' => 'tenant-123',
            'code' => 'TZH',
            'name' => 'Tanzania Health Network',
        ],
        'facility' => [
            'id' => 'facility-456',
            'code' => 'DAR-01',
            'name' => 'Dar Main',
        ],
        'resolvedFrom' => 'headers',
        'headers' => [
            'tenantCode' => 'TZH',
            'facilityCode' => 'DAR-01',
        ],
        'userAccess' => [
            'accessibleFacilityCount' => 2,
            'facilities' => [],
        ],
    ]);

    $context = new RequestCurrentPlatformScopeContext($request);

    expect($context->resolvedFrom())->toBe('headers');
    expect($context->tenantId())->toBe('tenant-123');
    expect($context->facilityId())->toBe('facility-456');
    expect($context->hasTenant())->toBeTrue();
    expect($context->hasFacility())->toBeTrue();
    expect($context->toArray()['headers']['tenantCode'])->toBe('TZH');
    expect($context->toArray()['userAccess']['accessibleFacilityCount'])->toBe(2);
});
