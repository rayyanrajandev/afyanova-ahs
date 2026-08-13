<?php

namespace App\Modules\PatientFlow\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PatientFlow\Application\UseCases\GetActiveVisitJourneyUseCase;
use App\Modules\PatientFlow\Application\UseCases\GetOrderCompletionNotificationsForClinicianUseCase;
use App\Modules\PatientFlow\Presentation\Http\Transformers\OrderCompletionNotificationResponseTransformer;
use App\Modules\PatientFlow\Presentation\Http\Transformers\VisitJourneyEntryResponseTransformer;
use App\Modules\Staff\Application\UseCases\ListStaffProfilesUseCase;
use App\Modules\Staff\Presentation\Http\Transformers\StaffProfileResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientFlowController extends Controller
{
    public function board(Request $request, GetActiveVisitJourneyUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                [VisitJourneyEntryResponseTransformer::class, 'transform'],
                $useCase->execute(
                    department: $request->string('department')->toString() ?: null,
                    clinicianUserId: $request->filled('clinicianUserId') ? $request->integer('clinicianUserId') : null,
                    q: $request->string('q')->toString() ?: null,
                ),
            ),
        ]);
    }

    /**
     * Mirrors TheatreProcedureController::clinicianDirectory() exactly — same
     * ListStaffProfilesUseCase, same forced status=active/clinicalOnly=true,
     * zero new use case. Board callers only need id->name resolution for the
     * doctor shown on a card, not a paginated staff browser.
     */
    public function clinicianDirectory(Request $request, ListStaffProfilesUseCase $useCase): JsonResponse
    {
        $filters = array_merge($request->all(), [
            'status' => 'active',
            'clinicalOnly' => true,
            'page' => max(1, (int) $request->integer('page', 1)),
            'perPage' => min(max((int) $request->integer('perPage', 200), 1), 200),
        ]);

        $result = $useCase->execute($filters);

        return response()->json([
            'data' => array_map([StaffProfileResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function notifications(Request $request, GetOrderCompletionNotificationsForClinicianUseCase $useCase): JsonResponse
    {
        $userId = $request->user()?->id;

        return response()->json([
            'data' => $userId === null ? [] : array_map(
                [OrderCompletionNotificationResponseTransformer::class, 'transform'],
                $useCase->execute($userId),
            ),
        ]);
    }
}
