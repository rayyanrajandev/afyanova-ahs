<?php

namespace App\Modules\Staff\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Staff\Application\Exceptions\DuplicateClinicalPrivilegeCatalogCodeException;
use App\Modules\Staff\Application\Exceptions\UnknownClinicalSpecialtyException;
use App\Modules\Staff\Application\UseCases\CreateClinicalPrivilegeCatalogUseCase;
use App\Modules\Staff\Application\UseCases\ListClinicalPrivilegeCatalogsUseCase;
use App\Modules\Staff\Application\UseCases\ListClinicalPrivilegeCatalogAuditLogsUseCase;
use App\Modules\Staff\Application\UseCases\UpdateClinicalPrivilegeCatalogStatusUseCase;
use App\Modules\Staff\Application\UseCases\UpdateClinicalPrivilegeCatalogUseCase;
use App\Modules\Staff\Presentation\Http\Requests\StoreClinicalPrivilegeCatalogRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateClinicalPrivilegeCatalogRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateClinicalPrivilegeCatalogStatusRequest;
use App\Modules\Staff\Presentation\Http\Transformers\ClinicalPrivilegeCatalogAuditLogResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\ClinicalPrivilegeCatalogResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicalPrivilegeCatalogController extends Controller
{
    public function index(Request $request, ListClinicalPrivilegeCatalogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([ClinicalPrivilegeCatalogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function store(StoreClinicalPrivilegeCatalogRequest $request, CreateClinicalPrivilegeCatalogUseCase $useCase): JsonResponse
    {
        try {
            $catalog = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateClinicalPrivilegeCatalogCodeException $exception) {
            return $this->validationError('code', $exception->getMessage());
        } catch (UnknownClinicalSpecialtyException $exception) {
            return $this->validationError('specialtyId', $exception->getMessage());
        }

        return response()->json([
            'data' => ClinicalPrivilegeCatalogResponseTransformer::transform($catalog),
        ], 201);
    }

    public function update(
        string $id,
        UpdateClinicalPrivilegeCatalogRequest $request,
        UpdateClinicalPrivilegeCatalogUseCase $useCase
    ): JsonResponse {
        try {
            $catalog = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateClinicalPrivilegeCatalogCodeException $exception) {
            return $this->validationError('code', $exception->getMessage());
        } catch (UnknownClinicalSpecialtyException $exception) {
            return $this->validationError('specialtyId', $exception->getMessage());
        }

        abort_if($catalog === null, 404, 'Privilege catalog not found.');

        return response()->json([
            'data' => ClinicalPrivilegeCatalogResponseTransformer::transform($catalog),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateClinicalPrivilegeCatalogStatusRequest $request,
        UpdateClinicalPrivilegeCatalogStatusUseCase $useCase
    ): JsonResponse {
        try {
            $catalog = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($catalog === null, 404, 'Privilege catalog not found.');

        return response()->json([
            'data' => ClinicalPrivilegeCatalogResponseTransformer::transform($catalog),
        ]);
    }

    public function auditLogs(
        string $id,
        Request $request,
        ListClinicalPrivilegeCatalogAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($id, $request->all());
        abort_if($result === null, 404, 'Privilege catalog not found.');

        return response()->json([
            'data' => array_map([ClinicalPrivilegeCatalogAuditLogResponseTransformer::class, 'transform'], $result['data']),
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
            'specialtyId' => 'specialty_id',
            'code' => 'code',
            'name' => 'name',
            'description' => 'description',
            'cadreCode' => 'cadre_code',
            'facilityType' => 'facility_type',
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
