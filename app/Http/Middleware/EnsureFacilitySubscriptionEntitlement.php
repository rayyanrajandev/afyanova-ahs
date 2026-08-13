<?php

namespace App\Http\Middleware;

use App\Modules\Platform\Application\Services\FacilitySubscriptionAccessService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureFacilitySubscriptionEntitlement
{
    public function __construct(private readonly FacilitySubscriptionAccessService $subscriptionAccessService) {}

    public function handle(Request $request, Closure $next, string ...$entitlements): Response
    {
        $result = $this->subscriptionAccessService->evaluate($entitlements);

        if ((bool) ($result['allowed'] ?? false)) {
            return $next($request);
        }

        if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
            return response()->json([
                'code' => $result['code'] ?? 'FACILITY_ENTITLEMENT_REQUIRED',
                'message' => $result['message'] ?? 'This facility subscription does not include the requested service.',
                'requiredEntitlements' => $result['requiredEntitlements'] ?? [],
                'missingEntitlements' => $result['missingEntitlements'] ?? [],
                'facility' => $result['facility'] ?? null,
                'subscription' => $result['subscription'] ?? null,
            ], 403);
        }

        return Inertia::render('errors/FacilitySubscriptionRequired', [
            'access' => [
                'code' => $result['code'] ?? 'FACILITY_ENTITLEMENT_REQUIRED',
                'message' => $result['message'] ?? 'This facility subscription does not include the requested service.',
                'requiredEntitlements' => $result['requiredEntitlements'] ?? [],
                'missingEntitlements' => $result['missingEntitlements'] ?? [],
                'facility' => $result['facility'] ?? null,
                'subscription' => $result['subscription'] ?? null,
            ],
        ])->toResponse($request)->setStatusCode(403);
    }
}
