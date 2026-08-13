<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Agent-Token');

        if (!$token || $token !== config('attendance.agent_token')) {
            return response()->json(['message' => 'Unauthorized agent.'], 401);
        }

        return $next($request);
    }
}
