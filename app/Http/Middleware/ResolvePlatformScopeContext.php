<?php

namespace App\Http\Middleware;

use App\Modules\Platform\Application\UseCases\ResolvePlatformAccessScopeUseCase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;

class ResolvePlatformScopeContext
{
    public function __construct(private readonly ResolvePlatformAccessScopeUseCase $useCase) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null) {
            $tenantHeader = (string) config('platform_scope.headers.tenant', 'X-Tenant-Code');
            $facilityHeader = (string) config('platform_scope.headers.facility', 'X-Facility-Code');
            $tenantCookie = (string) config('platform_scope.cookies.tenant', 'platform_tenant_code');
            $facilityCookie = (string) config('platform_scope.cookies.facility', 'platform_facility_code');
            $autoSelectSingleFacility = (bool) config('platform_scope.auto_select_single_facility', true);

            $tenantHeaderCode = $request->header($tenantHeader);
            $facilityHeaderCode = $request->header($facilityHeader);
            $tenantCode = $tenantHeaderCode ?? $request->cookie($tenantCookie);
            $facilityCode = $facilityHeaderCode ?? $request->cookie($facilityCookie);

            try {
                $scope = $this->useCase->execute(
                    userId: (int) $user->id,
                    tenantCode: is_string($tenantCode) ? $tenantCode : null,
                    facilityCode: is_string($facilityCode) ? $facilityCode : null,
                    autoSelectSingleFacility: $autoSelectSingleFacility,
                );
            } catch (AccessDeniedHttpException|NotFoundHttpException $exception) {
                // Keep strict API behavior for explicit bad headers.
                if ($tenantHeaderCode !== null || $facilityHeaderCode !== null) {
                    throw $exception;
                }

                // Ignore stale/invalid scope cookies and continue unresolved.
                $scope = $this->useCase->execute(
                    userId: (int) $user->id,
                    tenantCode: null,
                    facilityCode: null,
                    autoSelectSingleFacility: $autoSelectSingleFacility,
                );
            }

            $request->attributes->set('platform.scope', $scope);
        }

        return $next($request);
    }
}
