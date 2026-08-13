<?php

namespace App\Modules\Staff\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Staff\Application\Exceptions\DuplicateClinicalSpecialtyCodeException;
use App\Modules\Staff\Application\UseCases\CreateClinicalSpecialtyUseCase;
use App\Modules\Staff\Application\UseCases\GetClinicalSpecialtyUseCase;
use App\Modules\Staff\Application\UseCases\ListClinicalSpecialtyAssignedStaffUseCase;
use App\Modules\Staff\Application\UseCases\ListClinicalSpecialtyAuditLogsUseCase;
use App\Modules\Staff\Application\UseCases\ListClinicalSpecialtiesUseCase;
use App\Modules\Staff\Application\UseCases\UpdateClinicalSpecialtyStatusUseCase;
use App\Modules\Staff\Application\UseCases\UpdateClinicalSpecialtyUseCase;
use App\Modules\Staff\Presentation\Http\Requests\StoreClinicalSpecialtyRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateClinicalSpecialtyRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateClinicalSpecialtyStatusRequest;
use App\Modules\Staff\Presentation\Http\Transformers\ClinicalSpecialtyAssignedStaffResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\ClinicalSpecialtyAuditLogResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\ClinicalSpecialtyResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicalSpecialtyController extends Controller
{
    public function index(Request $request, ListClinicalSpecialtiesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([ClinicalSpecialtyResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function store(StoreClinicalSpecialtyRequest $request, CreateClinicalSpecialtyUseCase $useCase): JsonResponse
    {
        try {
            $specialty = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateClinicalSpecialtyCodeException $exception) {
            return $this->validationError('code', $exception->getMessage());
        }

        return response()->json([
            'data' => ClinicalSpecialtyResponseTransformer::transform($specialty),
        ], 201);
    }

    public function show(string $id, GetClinicalSpecialtyUseCase $useCase): JsonResponse
    {
        $specialty = $useCase->execute($id);
        abort_if($specialty === null, 404, 'Specialty not found.');

        return response()->json([
            'data' => ClinicalSpecialtyResponseTransformer::transform($specialty),
        ]);
    }

    public function update(
        string $id,
        UpdateClinicalSpecialtyRequest $request,
        UpdateClinicalSpecialtyUseCase $useCase
    ): JsonResponse {
        try {
            $specialty = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateClinicalSpecialtyCodeException $exception) {
            return $this->validationError('code', $exception->getMessage());
        }

        abort_if($specialty === null, 404, 'Specialty not found.');

        return response()->json([
            'data' => ClinicalSpecialtyResponseTransformer::transform($specialty),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateClinicalSpecialtyStatusRequest $request,
        UpdateClinicalSpecialtyStatusUseCase $useCase
    ): JsonResponse {
        try {
            $specialty = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($specialty === null, 404, 'Specialty not found.');

        return response()->json([
            'data' => ClinicalSpecialtyResponseTransformer::transform($specialty),
        ]);
    }

    public function auditLogs(
        string $id,
        Request $request,
        ListClinicalSpecialtyAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($id, $request->all());
        abort_if($result === null, 404, 'Specialty not found.');

        return response()->json([
            'data' => array_map([ClinicalSpecialtyAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function assignedStaff(
        string $id,
        Request $request,
        ListClinicalSpecialtyAssignedStaffUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($id, $request->all());
        abort_if($result === null, 404, 'Specialty not found.');

        return response()->json([
            'data' => array_map([ClinicalSpecialtyAssignedStaffResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    private function validationError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => 'VALIDATION_ERROR',
            'errors' => [
                $field => [$message],
            ],
        ], 422);
    }

    private function tenantScopeRequiredError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
    }

    private function toPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'code' => 'code',
            'name' => 'name',
            'description' => 'description',
        ];

        $payload = [];
        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        return $payload;
    }
}

