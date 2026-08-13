<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionLimits
{
    /**
     * Maximum concurrent sessions per user.
     */
    private const MAX_CONCURRENT_SESSIONS = 3;

    /**
     * Idle timeout in minutes (30 min).
     */
    private const IDLE_TIMEOUT_MINUTES = 30;

    /**
     * Absolute session lifetime in minutes (12 hours).
     */
    private const ABSOLUTE_TIMEOUT_MINUTES = 720;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            // Check absolute session timeout
            $sessionStartedAt = $request->session()->get('session_started_at');
            if ($sessionStartedAt === null) {
                $request->session()->put('session_started_at', now()->timestamp);
            } elseif (now()->diffInMinutes(now()->setTimestamp($sessionStartedAt)) > self::ABSOLUTE_TIMEOUT_MINUTES) {
                $request->session()->invalidate();
                Auth::logout();

                return $request->expectsJson()
                    ? response()->json(['message' => 'Session expired. Please login again.'], 401)
                    : redirect()->guest(route('login'));
            }

            // Check idle timeout
            $lastActivity = $request->session()->get('last_activity_time');
            if ($lastActivity !== null) {
                $idleMinutes = now()->diffInMinutes(now()->setTimestamp($lastActivity));
                if ($idleMinutes > self::IDLE_TIMEOUT_MINUTES) {
                    $request->session()->invalidate();
                    Auth::logout();

                    return $request->expectsJson()
                        ? response()->json(['message' => 'Session timed out due to inactivity.'], 401)
                        : redirect()->guest(route('login'));
                }
            }
            $request->session()->put('last_activity_time', now()->timestamp);

            // Check concurrent session limits
            $sessionId = $request->session()->getId();
            $cacheKey = "user_sessions_{$user->id}";
            $activeSessions = Cache::get($cacheKey, []);

            // Register this session
            if (!in_array($sessionId, $activeSessions, true)) {
                $activeSessions[] = $sessionId;
                Cache::put($cacheKey, $activeSessions, now()->addHours(24));
            }

            // Enforce limit
            if (count($activeSessions) > self::MAX_CONCURRENT_SESSIONS) {
                // Remove oldest session(s)
                while (count($activeSessions) > self::MAX_CONCURRENT_SESSIONS) {
                    $oldestSessionId = array_shift($activeSessions);
                    if ($oldestSessionId === $sessionId) {
                        // Current session would be removed — reject instead
                        $request->session()->invalidate();
                        Auth::logout();

                        return $request->expectsJson()
                            ? response()->json(['message' => 'Maximum concurrent sessions exceeded (max 3).'], 429)
                            : redirect()->guest(route('login'))->with('error', 'Maximum concurrent sessions exceeded (max 3).');
                    }
                }
                Cache::put($cacheKey, $activeSessions, now()->addHours(24));
            }
        }

        return $next($request);
    }
}