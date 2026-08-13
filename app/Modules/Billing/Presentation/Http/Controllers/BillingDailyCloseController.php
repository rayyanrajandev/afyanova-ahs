<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Application\UseCases\CreateBillingDailyCloseUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingDailyClosesUseCase;
use App\Modules\Billing\Presentation\Http\Requests\CreateBillingDailyCloseRequest;
use App\Modules\Billing\Presentation\Http\Transformers\BillingDailyCloseResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingDailyCloseController
{
    public function __construct(
        private readonly ListBillingDailyClosesUseCase $listUseCase,
        private readonly CreateBillingDailyCloseUseCase $createUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->listUseCase->execute($request->all());

        return response()->json([
            'data' => array_map([BillingDailyCloseResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function store(CreateBillingDailyCloseRequest $request): JsonResponse
    {
        $result = $this->createUseCase->execute(
            payload: $request->validated(),
            actorId: $request->user()?->id,
        );

        return response()->json([
            'data' => BillingDailyCloseResponseTransformer::transform($result),
        ], 201);
    }
}
