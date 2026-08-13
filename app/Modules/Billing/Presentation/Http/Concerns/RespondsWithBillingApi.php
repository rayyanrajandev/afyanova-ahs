<?php

namespace App\Modules\Billing\Presentation\Http\Concerns;

use Illuminate\Http\JsonResponse;

trait RespondsWithBillingApi
{
    /**
     * @param  array<string, mixed>|array<int, mixed>|null  $data
     * @param  array<string, mixed>|null  $meta
     */
    protected function successResponse(array|null $data = null, int $status = 200, ?array $meta = null): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    protected function notFoundResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'NOT_FOUND',
            'message' => $message,
        ], 404);
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    protected function unprocessableResponse(
        string $message,
        string $code = 'VALIDATION_ERROR',
        ?array $errors = null,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'code' => $code,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, 422);
    }
}
