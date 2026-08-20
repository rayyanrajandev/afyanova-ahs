<?php

namespace App\Modules\Revenue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Revenue\Application\UseCases\CancelServiceChargeUseCase;
use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Application\UseCases\WaiveServiceChargeUseCase;
use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Presentation\Http\Controllers\Concerns\RendersRevenueErrors;
use App\Modules\Revenue\Presentation\Http\Requests\ReasonedActionRequest;
use App\Modules\Revenue\Presentation\Http\Requests\StoreServiceChargeRequest;
use App\Modules\Revenue\Presentation\Http\Requests\WaiveServiceChargeRequest;
use App\Modules\Revenue\Presentation\Http\Transformers\ServiceChargeResponseTransformer;
use Illuminate\Http\JsonResponse;

class CashierChargeController extends Controller
{
    use RendersRevenueErrors;

    /**
     * An ad-hoc charge raised at the counter — a form, a card, a service with
     * no clinical order behind it.
     *
     * Clinically ordered items are refused here as well as hidden from the
     * search: hiding something in a picker is not a control when the endpoint
     * still accepts its id.
     */
    public function store(StoreServiceChargeRequest $request, RaiseServiceChargeUseCase $useCase): JsonResponse
    {
        return $this->renderingRevenueErrors(function () use ($request, $useCase): JsonResponse {
            $item = ChargeableItemModel::query()->find($request->string('chargeableItemId')->toString());

            abort_if($item === null, 404, 'Chargeable item not found.');

            $excluded = (array) config('revenue.counter_charge_excluded_catalog_types', []);

            if (in_array((string) $item->catalog_type, $excluded, true)) {
                return response()->json([
                    'message' => sprintf(
                        '%s is ordered clinically, so its charge is raised by the order — not at the counter.',
                        $item->name,
                    ),
                    'code' => 'COUNTER_CHARGE_NOT_ALLOWED',
                    'catalogType' => (string) $item->catalog_type,
                ], 422);
            }

            $charge = $useCase->execute(
                patientId: $request->string('patientId')->toString(),
                sourceKind: ChargeSourceKind::MANUAL,
                sourceId: null,
                chargeableItemId: (string) $item->id,
                description: $request->filled('description')
                    ? $request->string('description')->toString()
                    : (string) $item->name,
                quantity: (float) ($request->input('quantity') ?? 1.0),
                encounterId: $request->input('encounterId'),
                appointmentId: $request->input('appointmentId'),
                unit: (string) ($item->default_unit ?? 'unit'),
                actorUserId: $request->user()?->id,
            );

            return response()->json([
                'data' => ServiceChargeResponseTransformer::transform($charge),
            ], 201);
        });
    }

    public function cancel(
        string $id,
        ReasonedActionRequest $request,
        CancelServiceChargeUseCase $useCase,
    ): JsonResponse {
        return $this->renderingRevenueErrors(fn (): JsonResponse => response()->json([
            'data' => ServiceChargeResponseTransformer::transform(
                $useCase->execute($id, $request->string('reason')->toString(), (int) $request->user()?->id),
            ),
        ]));
    }

    /**
     * A supervisor writes the charge off before service.
     */
    public function waive(
        string $id,
        WaiveServiceChargeRequest $request,
        WaiveServiceChargeUseCase $useCase,
    ): JsonResponse {
        return $this->authorizeWithoutPayment($id, AuthorizationBasis::WAIVER, $request, $useCase);
    }

    /**
     * Treat now, reconcile later.
     *
     * Separate from waive because the decision is clinical, not financial —
     * it is held by triage and clinical leads and by no finance role, which a
     * shared route could not express.
     */
    public function emergencyOverride(
        string $id,
        WaiveServiceChargeRequest $request,
        WaiveServiceChargeUseCase $useCase,
    ): JsonResponse {
        return $this->authorizeWithoutPayment($id, AuthorizationBasis::EMERGENCY, $request, $useCase);
    }

    private function authorizeWithoutPayment(
        string $id,
        AuthorizationBasis $basis,
        WaiveServiceChargeRequest $request,
        WaiveServiceChargeUseCase $useCase,
    ): JsonResponse {
        return $this->renderingRevenueErrors(fn (): JsonResponse => response()->json([
            'data' => ServiceChargeResponseTransformer::transform(
                $useCase->execute(
                    serviceChargeId: $id,
                    basis: $basis,
                    reason: $request->string('reason')->toString(),
                    approvedByUserId: (int) $request->user()?->id,
                ),
            ),
        ]));
    }
}
