<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Application\UseCases\ApproveBillingWriteOffUseCase;
use App\Modules\Billing\Application\UseCases\CreateBillingWriteOffUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingWriteOffsUseCase;
use App\Modules\Billing\Domain\Repositories\BillingWriteOffRepositoryInterface;
use App\Modules\Billing\Presentation\Http\Requests\ApproveBillingWriteOffRequest;
use App\Modules\Billing\Presentation\Http\Requests\CreateBillingWriteOffRequest;
use App\Modules\Billing\Presentation\Http\Transformers\BillingWriteOffResponseTransformer;
use App\Modules\Billing\Presentation\Http\Concerns\RespondsWithBillingApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingWriteOffController
{
    use RespondsWithBillingApi;

    public function __construct(
        private readonly ListBillingWriteOffsUseCase $listUseCase,
        private readonly CreateBillingWriteOffUseCase $createUseCase,
        private readonly ApproveBillingWriteOffUseCase $approveUseCase,
        private readonly BillingWriteOffRepositoryInterface $repository,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->listUseCase->execute($request->all());

        return response()->json([
            'data' => array_map([BillingWriteOffResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function store(CreateBillingWriteOffRequest $request): JsonResponse
    {
        $result = $this->createUseCase->execute(
            payload: $request->validated(),
            actorId: $request->user()?->id,
        );

        return response()->json([
            'data' => BillingWriteOffResponseTransformer::transform($result),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $writeOff = $this->repository->findById($id);
        abort_if($writeOff === null, 404, 'Write-off not found.');

        return response()->json([
            'data' => BillingWriteOffResponseTransformer::transform($writeOff),
        ]);
    }

    public function approve(string $id, ApproveBillingWriteOffRequest $request): JsonResponse
    {
        try {
            $result = $this->approveUseCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                notes: $request->input('notes'),
                actorId: $request->user()?->id,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        return response()->json([
            'data' => BillingWriteOffResponseTransformer::transform($result),
        ]);
    }
}
