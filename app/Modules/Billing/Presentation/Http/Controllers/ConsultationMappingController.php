<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Infrastructure\Models\ConsultationMappingModel;
use App\Modules\Billing\Presentation\Http\Concerns\RespondsWithBillingApi;
use App\Modules\Billing\Presentation\Http\Requests\StoreConsultationMappingRequest;
use App\Modules\Billing\Presentation\Http\Requests\UpdateConsultationMappingRequest;
use Illuminate\Http\JsonResponse;

class ConsultationMappingController
{
    use RespondsWithBillingApi;

    public function index(): JsonResponse
    {
        $mappings = ConsultationMappingModel::query()
            ->with('chargeableItem.priceBookEntries')
            ->orderBy('clinician_tier')
            ->orderBy('department')
            ->get();

        return $this->successResponse(
            $mappings->map(fn (ConsultationMappingModel $mapping) => $this->transform($mapping))->all(),
        );
    }

    public function store(StoreConsultationMappingRequest $request): JsonResponse
    {
        $mapping = ConsultationMappingModel::create($request->validated());
        $mapping->load('chargeableItem.priceBookEntries');

        return $this->successResponse(
            data: $this->transform($mapping),
            status: 201,
        );
    }

    public function update(string $mappingId, UpdateConsultationMappingRequest $request): JsonResponse
    {
        $mapping = ConsultationMappingModel::find($mappingId);

        if ($mapping === null) {
            return $this->notFoundResponse('Consultation mapping not found');
        }

        $mapping->update($request->validated());
        $mapping->load('chargeableItem.priceBookEntries');

        return $this->successResponse($this->transform($mapping));
    }

    public function destroy(string $mappingId): JsonResponse
    {
        $mapping = ConsultationMappingModel::find($mappingId);

        if ($mapping === null) {
            return $this->notFoundResponse('Consultation mapping not found');
        }

        $mapping->delete();

        return $this->successResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(ConsultationMappingModel $mapping): array
    {
        $chargeableItem = $mapping->chargeableItem;
        $firstPriceEntry = $chargeableItem?->priceBookEntries->first();

        return [
            'id' => (string) $mapping->id,
            'clinician_tier' => $mapping->clinician_tier,
            'department' => $mapping->department,
            'chargeable_item_id' => $mapping->chargeable_item_id === null ? null : (string) $mapping->chargeable_item_id,
            'catalog_item' => $chargeableItem === null ? null : [
                'id' => (string) $chargeableItem->id,
                'service_code' => $chargeableItem->code,
                'service_name' => $chargeableItem->name,
                'base_price' => $firstPriceEntry?->unit_price ?? 0,
                'status' => $chargeableItem->status ?? 'active',
            ],
            'created_at' => $mapping->created_at?->toISOString(),
            'updated_at' => $mapping->updated_at?->toISOString(),
        ];
    }
}
