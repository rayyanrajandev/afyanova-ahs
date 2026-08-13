<?php

namespace App\Modules\Staff\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Staff\Application\Exceptions\InvalidStaffSpecialtyAssignmentsException;
use App\Modules\Staff\Application\Exceptions\UnknownClinicalSpecialtyException;
use App\Modules\Staff\Application\UseCases\ListStaffProfileSpecialtiesUseCase;
use App\Modules\Staff\Application\UseCases\SyncStaffProfileSpecialtiesUseCase;
use App\Modules\Staff\Presentation\Http\Requests\SyncStaffProfileSpecialtiesRequest;
use App\Modules\Staff\Presentation\Http\Transformers\StaffProfileSpecialtyResponseTransformer;
use Illuminate\Http\JsonResponse;

class StaffProfileSpecialtyController extends Controller
{
    public function index(string $id, ListStaffProfileSpecialtiesUseCase $useCase): JsonResponse
    {
        $assignments = $useCase->execute($id);
        abort_if($assignments === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => array_map([StaffProfileSpecialtyResponseTransformer::class, 'transform'], $assignments),
        ]);
    }

    public function sync(
        string $id,
        SyncStaffProfileSpecialtiesRequest $request,
        SyncStaffProfileSpecialtiesUseCase $useCase
    ): JsonResponse {
        try {
            $assignments = $useCase->execute(
                staffProfileId: $id,
                assignments: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (UnknownClinicalSpecialtyException|InvalidStaffSpecialtyAssignmentsException $exception) {
            return $this->validationError('specialtyAssignments', $exception->getMessage());
        }

        abort_if($assignments === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => array_map([StaffProfileSpecialtyResponseTransformer::class, 'transform'], $assignments),
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

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    private function toPersistencePayload(array $validated): array
    {
        $assignments = $validated['specialtyAssignments'] ?? [];
        if (! is_array($assignments)) {
            return [];
        }

        return array_map(static fn (array $assignment): array => [
            'specialty_id' => $assignment['specialtyId'] ?? null,
            'is_primary' => (bool) ($assignment['isPrimary'] ?? false),
        ], $assignments);
    }
}

