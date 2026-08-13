<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Application\UseCases\DetermineBillingRouteUseCase;
use App\Modules\Billing\Presentation\Http\Concerns\RespondsWithBillingApi;
use App\Modules\Billing\Presentation\Http\Requests\DetermineBillingRouteRequest;
use App\Modules\Billing\Presentation\Http\Transformers\BillingRoutingDecisionResponseTransformer;
use Illuminate\Http\JsonResponse;

class BillingRoutingController
{
    use RespondsWithBillingApi;

    public function __construct(
        private readonly DetermineBillingRouteUseCase $determineBillingRouteUseCase,
    ) {}

    public function determine(DetermineBillingRouteRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->successResponse(
            BillingRoutingDecisionResponseTransformer::transform(
                $this->determineBillingRouteUseCase->execute($validated),
            ),
        );
    }
}
