<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasActiveRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isPlatformSuperAdminAccess()) {
            // Block unverified users — email verification is required before
            // accessing any authenticated route. This prevents session hijacking
            // via unverified accounts (see §3.1.1 of Security Architecture).
            if ($user->email_verified_at === null) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Email verification required.'], 403)
                    : redirect()->guest(route('verification.notice'));
            }

            $hasActiveRole = $user->roles()
                ->active()
                ->exists();

            if (! $hasActiveRole) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'User has no active roles assigned.'], 403)
                    : redirect()->guest(route('pending-setup'));
            }
        }

        return $next($request);
    }
}
