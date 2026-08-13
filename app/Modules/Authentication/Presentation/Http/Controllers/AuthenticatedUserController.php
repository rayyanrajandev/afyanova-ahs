<?php

namespace App\Modules\Authentication\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Application\UseCases\GetAuthenticatedUserPermissionsUseCase;
use App\Modules\Authentication\Application\UseCases\GetAuthenticatedUserProfileUseCase;
use App\Modules\Authentication\Application\UseCases\GetAuthenticatedUserSecurityStatusUseCase;
use App\Modules\Authentication\Presentation\Http\Transformers\AuthenticatedUserProfileResponseTransformer;
use App\Modules\Authentication\Presentation\Http\Transformers\AuthenticatedUserSecurityStatusResponseTransformer;
use App\Support\Auth\EffectivePermissionNameResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthenticatedUserController extends Controller
{
    public function me(Request $request, GetAuthenticatedUserProfileUseCase $useCase): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $profile = $useCase->execute($userId);
        abort_if($profile === null, 404, 'Authenticated user not found.');

        return response()->json([
            'data' => AuthenticatedUserProfileResponseTransformer::transform($profile),
        ]);
    }

    public function permissions(Request $request, GetAuthenticatedUserPermissionsUseCase $useCase): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $permissions = $useCase->execute($userId);

        /** @var EffectivePermissionNameResolver $resolver */
        $resolver = app(EffectivePermissionNameResolver::class);
        $permissions = $resolver->resolve($request->user(), $permissions);

        return response()->json([
            'data' => array_map(
                static fn (string $permission): array => ['name' => $permission],
                $permissions,
            ),
            'meta' => [
                'total' => count($permissions),
            ],
        ]);
    }

    public function securityStatus(Request $request, GetAuthenticatedUserSecurityStatusUseCase $useCase): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $status = $useCase->execute($userId);
        abort_if($status === null, 404, 'Authenticated user not found.');

        return response()->json([
            'data' => AuthenticatedUserSecurityStatusResponseTransformer::transform($status),
        ]);
    }
}
