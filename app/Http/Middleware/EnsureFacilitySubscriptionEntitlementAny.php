<?php

namespace App\Http\Middleware;

use App\Modules\Platform\Application\Services\FacilitySubscriptionAccessService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Facility plan must include at least one of the listed entitlements (comma-separated route parameter).
 */
class EnsureFacilitySubscriptionEntitlementAny
{
    public function __construct(private readonly FacilitySubscriptionAccessService $subscriptionAccessService) {}

    public function handle(Request $request, Closure $next, string ...$specParts): Response
    {
        $raw = trim(implode(',', $specParts));
        $alternatives = array_values(array_filter(array_map(
            static fn (string $chunk): string => trim($chunk),
            explode(',', $raw),
        )));

        $result = $this->subscriptionAccessService->evaluateAny($alternatives);

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
