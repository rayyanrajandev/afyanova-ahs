<?php

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlementAny;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureUserHasActiveRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolvePlatformScopeContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
        __DIR__.'/../app/Modules/InventoryProcurement/Application/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: [
            'appearance',
            'sidebar_state',
            'platform_tenant_code',
            'platform_facility_code',
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            ResolvePlatformScopeContext::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Shared stack for the authenticated api/v1 surface. Defined here, once,
        // because routes/api.php (loaded by withRouting) and routes/api-workspaces.php
        // (loaded by WorkspaceRouteServiceProvider) previously each declared this
        // array verbatim — adding a middleware to one and forgetting the other
        // silently dropped tenant isolation or entitlement checks on half the API.
        $middleware->appendToGroup('api.platform', [
            'web',
            'auth',
            ResolvePlatformScopeContext::class,
            EnforceTenantIsolationWhenEnabled::class,
            'session.limits',
            EnsureMappedFacilitySubscriptionEntitlement::class,
        ]);

        $middleware->alias([
            'agent.token' => App\Http\Middleware\EnsureValidAgentToken::class,
            'facility.entitlement' => EnsureFacilitySubscriptionEntitlement::class,
            'facility.entitlement.any' => EnsureFacilitySubscriptionEntitlementAny::class,
            'user.has-role' => EnsureUserHasActiveRole::class,
            'session.limits' => App\Http\Middleware\EnforceSessionLimits::class,
        ]);


    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
