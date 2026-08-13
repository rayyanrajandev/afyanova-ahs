<?php

namespace App\Modules\Platform\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\UseCases\GetDashboardContextUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardContextController extends Controller
{
    public function show(Request $request, GetDashboardContextUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase->execute($request->user()),
        ]);
    }
}
