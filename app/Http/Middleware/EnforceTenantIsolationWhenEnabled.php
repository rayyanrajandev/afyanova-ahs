<?php

namespace App\Http\Middleware;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnforceTenantIsolationWhenEnabled
{
    public function __construct(
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation')) {
            return $next($request);
        }

        // Platform/auth endpoints must remain reachable to discover scope and session state.
        if ($request->routeIs('platform.*') || $request->routeIs('auth.me*')) {
            return $next($request);
        }

        if (! $this->scopeContext->hasTenant()) {
            $routeName = $request->route()?->getName();

            // For web/Inertia requests, redirect to a proper page rather than returning
            // raw JSON (which breaks Inertia's page resolution).
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return redirect()->guest(route('pending-setup'));
            }

            return new JsonResponse([
                'code' => 'TENANT_SCOPE_REQUIRED',
                'message' => 'Tenant scope is required when multi-tenant isolation is enabled.',
                'meta' => [
                    'flagName' => 'platform.multi_tenant_isolation',
                    'resolvedFrom' => $this->scopeContext->resolvedFrom(),
                    'routeName' => is_string($routeName) && $routeName !== '' ? $routeName : null,
                ],
            ], 403);
        }

        $tenantId = $this->scopeContext->tenantId();

        // Set PostgreSQL session-level tenant context for RLS policies (PostgreSQL only)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL app.tenant_id = '{$tenantId}'");
            DB::statement("SET LOCAL app.bypass_tenant_isolation = 'false'");
        }

        // Bind tenant-scoped context for downstream services
        app()->instance('current.tenant_id', $tenantId);

        // Log scope for audit context
        Log::withContext(['tenant_id' => $tenantId]);

        return $next($request);
    }
}
