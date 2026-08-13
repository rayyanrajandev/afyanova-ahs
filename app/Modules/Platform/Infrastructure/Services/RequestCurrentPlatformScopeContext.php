<?php

namespace App\Modules\Platform\Infrastructure\Services;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Http\Request;

class RequestCurrentPlatformScopeContext implements CurrentPlatformScopeContextInterface
{
    public function __construct(private readonly ?Request $fallbackRequest = null) {}

    public function toArray(): array
    {
        $request = $this->currentRequest();
        if ($request === null) {
            return $this->defaultScope();
        }

        $scope = $request->attributes->get('platform.scope');

        if (! is_array($scope)) {
            return $this->defaultScope();
        }

        return [
            'tenant' => isset($scope['tenant']) && is_array($scope['tenant']) ? $scope['tenant'] : null,
            'facility' => isset($scope['facility']) && is_array($scope['facility']) ? $scope['facility'] : null,
            'resolvedFrom' => is_string($scope['resolvedFrom'] ?? null) ? $scope['resolvedFrom'] : 'none',
            'headers' => isset($scope['headers']) && is_array($scope['headers'])
                ? array_merge([
                    'tenantCode' => null,
                    'facilityCode' => null,
                ], $scope['headers'])
                : [
                    'tenantCode' => null,
                    'facilityCode' => null,
                ],
            'userAccess' => isset($scope['userAccess']) && is_array($scope['userAccess'])
                ? array_merge([
                    'accessibleFacilityCount' => 0,
                    'facilities' => [],
                ], $scope['userAccess'])
                : [
                    'accessibleFacilityCount' => 0,
                    'facilities' => [],
                ],
        ];
    }

    public function tenant(): ?array
    {
        $tenant = $this->toArray()['tenant'] ?? null;

        return is_array($tenant) ? $tenant : null;
    }

    public function facility(): ?array
    {
        $facility = $this->toArray()['facility'] ?? null;

        return is_array($facility) ? $facility : null;
    }

    public function tenantId(): ?string
    {
        $tenant = $this->tenant();
        $id = $tenant['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function facilityId(): ?string
    {
        $facility = $this->facility();
        $id = $facility['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function resolvedFrom(): string
    {
        $resolvedFrom = $this->toArray()['resolvedFrom'] ?? 'none';

        return is_string($resolvedFrom) ? $resolvedFrom : 'none';
    }

    public function hasTenant(): bool
    {
        return $this->tenantId() !== null;
    }

    public function hasFacility(): bool
    {
        return $this->facilityId() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultScope(): array
    {
        return [
            'tenant' => null,
            'facility' => null,
            'resolvedFrom' => 'none',
            'headers' => [
                'tenantCode' => null,
                'facilityCode' => null,
            ],
            'userAccess' => [
                'accessibleFacilityCount' => 0,
                'facilities' => [],
            ],
        ];
    }

    private function currentRequest(): ?Request
    {
        if (app()->bound('request')) {
            $request = app('request');
            if ($request instanceof Request) {
                return $request;
            }
        }

        return $this->fallbackRequest;
    }
}
