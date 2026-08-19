<?php

namespace App\Modules\Revenue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Revenue\Application\UseCases\GetCashierDaySummaryUseCase;
use App\Modules\Revenue\Application\UseCases\ListCashierQueueUseCase;
use App\Modules\Revenue\Application\UseCases\ListPatientChargesUseCase;
use App\Modules\Revenue\Application\UseCases\SearchChargeableItemsUseCase;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use App\Modules\Revenue\Presentation\Http\Transformers\ServiceChargeResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierQueueController extends Controller
{
    public function index(Request $request, ListCashierQueueUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json($result);
    }

    public function statusCounts(ListCashierQueueUseCase $useCase): JsonResponse
    {
        return response()->json(['data' => $useCase->statusCounts()]);
    }

    public function patientCharges(
        string $patientId,
        Request $request,
        ListPatientChargesUseCase $useCase,
    ): JsonResponse {
        $result = $useCase->execute(
            patientId: $patientId,
            includeSettled: $request->boolean('includeSettled'),
        );

        return response()->json([
            'data' => array_map(
                [ServiceChargeResponseTransformer::class, 'transform'],
                array_values($result['data']),
            ),
            'meta' => $result['meta'],
        ]);
    }

    /**
     * The price list, for raising an ad-hoc charge.
     */
    public function catalog(Request $request, SearchChargeableItemsUseCase $useCase): JsonResponse
    {
        return response()->json($useCase->execute(
            $request->query('q'),
            $request->query('catalogType'),
        ));
    }

    public function daySummary(Request $request, GetCashierDaySummaryUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase->execute($request->query('date')),
        ]);
    }

    public function showCharge(string $id): JsonResponse
    {
        $charge = ServiceChargeModel::query()->find($id);

        abort_if($charge === null, 404, 'Charge not found.');

        return response()->json([
            'data' => ServiceChargeResponseTransformer::transform($charge),
        ]);
    }
}
