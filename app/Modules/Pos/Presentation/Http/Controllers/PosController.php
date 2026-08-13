<?php

namespace App\Modules\Pos\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Pos\Application\UseCases\CreateCafeteriaPosSaleUseCase;
use App\Modules\Pos\Application\UseCases\CreateLabQuickPosSaleUseCase;
use App\Modules\Pos\Application\UseCases\CreatePosCafeteriaMenuItemUseCase;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Application\UseCases\ClosePosRegisterSessionUseCase;
use App\Modules\Pos\Application\UseCases\CreatePharmacyOtcSaleUseCase;
use App\Modules\Pos\Application\UseCases\CreatePosRegisterUseCase;
use App\Modules\Pos\Application\UseCases\CreatePosSaleUseCase;
use App\Modules\Pos\Application\UseCases\GetPosCafeteriaMenuItemUseCase;
use App\Modules\Pos\Application\UseCases\GetPosRegisterSessionUseCase;
use App\Modules\Pos\Application\UseCases\GetPosRegisterUseCase;
use App\Modules\Pos\Application\UseCases\GetPosSaleUseCase;
use App\Modules\Pos\Application\UseCases\ListLabQuickCashierCandidatesUseCase;
use App\Modules\Pos\Application\UseCases\ListPharmacyOtcCatalogUseCase;
use App\Modules\Pos\Application\UseCases\ListPosCafeteriaMenuItemsUseCase;
use App\Modules\Pos\Application\UseCases\ListPosRegistersUseCase;
use App\Modules\Pos\Application\UseCases\ListPosRegisterSessionsUseCase;
use App\Modules\Pos\Application\UseCases\ListPosSalesUseCase;
use App\Modules\Pos\Application\UseCases\OpenPosRegisterSessionUseCase;
use App\Modules\Pos\Application\UseCases\RefundPosSaleUseCase;
use App\Modules\Pos\Application\UseCases\UpdatePosCafeteriaMenuItemUseCase;
use App\Modules\Pos\Application\UseCases\UpdatePosRegisterUseCase;
use App\Modules\Pos\Application\UseCases\VoidPosSaleUseCase;
use App\Modules\Pos\Presentation\Http\Requests\ClosePosRegisterSessionRequest;
use App\Modules\Pos\Presentation\Http\Requests\OpenPosRegisterSessionRequest;
use App\Modules\Pos\Presentation\Http\Requests\RefundPosSaleRequest;
use App\Modules\Pos\Presentation\Http\Requests\StoreLabQuickPosSaleRequest;
use App\Modules\Pos\Presentation\Http\Requests\StoreCafeteriaPosSaleRequest;
use App\Modules\Pos\Presentation\Http\Requests\StorePharmacyOtcSaleRequest;
use App\Modules\Pos\Presentation\Http\Requests\StorePosCafeteriaMenuItemRequest;
use App\Modules\Pos\Presentation\Http\Requests\StorePosRegisterRequest;
use App\Modules\Pos\Presentation\Http\Requests\StorePosSaleRequest;
use App\Modules\Pos\Presentation\Http\Requests\UpdatePosCafeteriaMenuItemRequest;
use App\Modules\Pos\Presentation\Http\Requests\UpdatePosRegisterRequest;
use App\Modules\Pos\Presentation\Http\Requests\VoidPosSaleRequest;
use App\Modules\Pos\Presentation\Http\Transformers\PosCafeteriaMenuItemResponseTransformer;
use App\Modules\Pos\Application\UseCases\CreateFrontdeskQuickPosSaleUseCase;
use App\Modules\Pos\Application\UseCases\ListFrontdeskQuickCandidatesUseCase;
use App\Modules\Pos\Application\UseCases\VerifyFrontdeskQuickPosPaymentUseCase;
use App\Modules\Pos\Presentation\Http\Requests\StoreFrontdeskQuickPosSaleRequest;
use App\Modules\Pos\Presentation\Http\Transformers\PosFrontdeskQuickCandidateResponseTransformer;
use App\Modules\Pos\Presentation\Http\Transformers\PosFrontdeskQuickPaymentVerificationTransformer;
use App\Modules\Pos\Presentation\Http\Transformers\PosLabQuickCandidateResponseTransformer;
use App\Modules\Pos\Presentation\Http\Transformers\PosPharmacyOtcCatalogItemResponseTransformer;
use App\Modules\Pos\Presentation\Http\Transformers\PosRegisterResponseTransformer;
use App\Modules\Pos\Presentation\Http\Transformers\PosRegisterSessionResponseTransformer;
use App\Modules\Pos\Presentation\Http\Transformers\PosSaleResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosController extends Controller
{
    public function registers(Request $request, ListPosRegistersUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([PosRegisterResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function storeRegister(StorePosRegisterRequest $request, CreatePosRegisterUseCase $useCase): JsonResponse
    {
        try {
            $register = $useCase->execute(
                payload: $this->toRegisterPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        return response()->json([
            'data' => PosRegisterResponseTransformer::transform($register),
        ], 201);
    }

    public function showRegister(string $id, GetPosRegisterUseCase $useCase): JsonResponse
    {
        $register = $useCase->execute($id);
        abort_if($register === null, 404, 'POS register not found.');

        return response()->json([
            'data' => PosRegisterResponseTransformer::transform($register),
        ]);
    }

    public function updateRegister(
        string $id,
        UpdatePosRegisterRequest $request,
        UpdatePosRegisterUseCase $useCase
    ): JsonResponse {
        try {
            $register = $useCase->execute(
                id: $id,
                payload: $this->toRegisterPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($register === null, 404, 'POS register not found.');

        return response()->json([
            'data' => PosRegisterResponseTransformer::transform($register),
        ]);
    }

    public function sessions(Request $request, ListPosRegisterSessionsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([PosRegisterSessionResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function openSession(
        string $registerId,
        OpenPosRegisterSessionRequest $request,
        OpenPosRegisterSessionUseCase $useCase
    ): JsonResponse {
        try {
            $session = $useCase->execute(
                registerId: $registerId,
                payload: $this->toOpenSessionPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($session === null, 404, 'POS register not found.');

        return response()->json([
            'data' => PosRegisterSessionResponseTransformer::transform($session),
        ], 201);
    }

    public function showSession(string $id, GetPosRegisterSessionUseCase $useCase): JsonResponse
    {
        $session = $useCase->execute($id);
        abort_if($session === null, 404, 'POS register session not found.');

        return response()->json([
            'data' => PosRegisterSessionResponseTransformer::transform($session),
        ]);
    }

    public function closeSession(
        string $id,
        ClosePosRegisterSessionRequest $request,
        ClosePosRegisterSessionUseCase $useCase
    ): JsonResponse {
        try {
            $session = $useCase->execute(
                id: $id,
                payload: $this->toCloseSessionPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($session === null, 404, 'POS register session not found.');

        return response()->json([
            'data' => PosRegisterSessionResponseTransformer::transform($session),
        ]);
    }

    public function sales(Request $request, ListPosSalesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([PosSaleResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function cafeteriaCatalog(Request $request, ListPosCafeteriaMenuItemsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map(
                [PosCafeteriaMenuItemResponseTransformer::class, 'transform'],
                $result['data'],
            ),
            'meta' => $result['meta'],
        ]);
    }

    public function storeCafeteriaCatalogItem(
        StorePosCafeteriaMenuItemRequest $request,
        CreatePosCafeteriaMenuItemUseCase $useCase
    ): JsonResponse {
        try {
            $item = $useCase->execute(
                payload: $this->toCafeteriaMenuItemPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        return response()->json([
            'data' => PosCafeteriaMenuItemResponseTransformer::transform($item),
        ], 201);
    }

    public function showCafeteriaCatalogItem(string $id, GetPosCafeteriaMenuItemUseCase $useCase): JsonResponse
    {
        $item = $useCase->execute($id);
        abort_if($item === null, 404, 'POS cafeteria menu item not found.');

        return response()->json([
            'data' => PosCafeteriaMenuItemResponseTransformer::transform($item),
        ]);
    }

    public function updateCafeteriaCatalogItem(
        string $id,
        UpdatePosCafeteriaMenuItemRequest $request,
        UpdatePosCafeteriaMenuItemUseCase $useCase
    ): JsonResponse {
        try {
            $item = $useCase->execute(
                id: $id,
                payload: $this->toCafeteriaMenuItemPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($item === null, 404, 'POS cafeteria menu item not found.');

        return response()->json([
            'data' => PosCafeteriaMenuItemResponseTransformer::transform($item),
        ]);
    }

    public function pharmacyOtcCatalog(Request $request, ListPharmacyOtcCatalogUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map(
                [PosPharmacyOtcCatalogItemResponseTransformer::class, 'transform'],
                $result['data'],
            ),
            'meta' => $result['meta'],
        ]);
    }

    public function labQuickCandidates(Request $request, ListLabQuickCashierCandidatesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map(
                [PosLabQuickCandidateResponseTransformer::class, 'transform'],
                $result['data'],
            ),
            'meta' => $result['meta'],
        ]);
    }

    public function storePharmacyOtcSale(
        StorePharmacyOtcSaleRequest $request,
        CreatePharmacyOtcSaleUseCase $useCase
    ): JsonResponse {
        try {
            $sale = $useCase->execute(
                payload: $this->toPharmacyOtcSalePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($sale === null, 404, 'POS register not found.');

        return response()->json([
            'data' => PosSaleResponseTransformer::transform($sale),
        ], 201);
    }

    public function storeLabQuickSale(
        StoreLabQuickPosSaleRequest $request,
        CreateLabQuickPosSaleUseCase $useCase
    ): JsonResponse {
        try {
            $sale = $useCase->execute(
                payload: $this->toLabQuickSalePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($sale === null, 404, 'POS register not found.');

        return response()->json([
            'data' => PosSaleResponseTransformer::transform($sale),
        ], 201);
    }

    public function frontdeskQuickCandidates(Request $request, ListFrontdeskQuickCandidatesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map(
                [PosFrontdeskQuickCandidateResponseTransformer::class, 'transform'],
                $result['data'],
            ),
            'meta' => $result['meta'],
        ]);
    }

    public function storeFrontdeskQuickSale(
        StoreFrontdeskQuickPosSaleRequest $request,
        CreateFrontdeskQuickPosSaleUseCase $useCase
    ): JsonResponse {
        try {
            $sale = $useCase->execute(
                payload: $this->toFrontdeskQuickSalePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($sale === null, 404, 'POS register not found.');

        return response()->json([
            'data' => PosSaleResponseTransformer::transform($sale),
        ], 201);
    }

    public function verifyFrontdeskQuickPayment(
        string $sourceKind,
        string $orderId,
        VerifyFrontdeskQuickPosPaymentUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($sourceKind, $orderId);

        return response()->json([
            'data' => PosFrontdeskQuickPaymentVerificationTransformer::transform($result),
        ]);
    }

    public function storeCafeteriaSale(
        StoreCafeteriaPosSaleRequest $request,
        CreateCafeteriaPosSaleUseCase $useCase
    ): JsonResponse {
        try {
            $sale = $useCase->execute(
                payload: $this->toCafeteriaSalePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($sale === null, 404, 'POS register not found.');

        return response()->json([
            'data' => PosSaleResponseTransformer::transform($sale),
        ], 201);
    }

    public function storeSale(StorePosSaleRequest $request, CreatePosSaleUseCase $useCase): JsonResponse
    {
        try {
            $sale = $useCase->execute(
                payload: $this->toSalePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($sale === null, 404, 'POS register not found.');

        return response()->json([
            'data' => PosSaleResponseTransformer::transform($sale),
        ], 201);
    }

    public function showSale(string $id, GetPosSaleUseCase $useCase): JsonResponse
    {
        $sale = $useCase->execute($id);
        abort_if($sale === null, 404, 'POS sale not found.');

        return response()->json([
            'data' => PosSaleResponseTransformer::transform($sale),
        ]);
    }

    public function voidSale(
        string $id,
        VoidPosSaleRequest $request,
        VoidPosSaleUseCase $useCase
    ): JsonResponse {
        try {
            $sale = $useCase->execute(
                id: $id,
                payload: $this->toVoidSalePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($sale === null, 404, 'POS sale not found.');

        return response()->json([
            'data' => PosSaleResponseTransformer::transform($sale),
        ]);
    }

    public function refundSale(
        string $id,
        RefundPosSaleRequest $request,
        RefundPosSaleUseCase $useCase
    ): JsonResponse {
        try {
            $sale = $useCase->execute(
                id: $id,
                payload: $this->toRefundSalePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PosOperationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        abort_if($sale === null, 404, 'POS sale not found.');

        return response()->json([
            'data' => PosSaleResponseTransformer::transform($sale),
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

    private function toRegisterPayload(array $validated): array
    {
        $fieldMap = [
            'registerCode' => 'register_code',
            'registerName' => 'register_name',
            'location' => 'location',
            'defaultCurrencyCode' => 'default_currency_code',
            'status' => 'status',
            'statusReason' => 'status_reason',
            'notes' => 'notes',
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

    private function toOpenSessionPayload(array $validated): array
    {
        return [
            'opening_cash_amount' => $validated['openingCashAmount'],
            'opening_note' => $validated['openingNote'] ?? null,
        ];
    }

    private function toCloseSessionPayload(array $validated): array
    {
        return [
            'closing_cash_amount' => $validated['closingCashAmount'],
            'closing_note' => $validated['closingNote'] ?? null,
        ];
    }

    private function toSalePayload(array $validated): array
    {
        return [
            'pos_register_id' => $validated['registerId'],
            'patient_id' => $validated['patientId'] ?? null,
            'sale_channel' => $validated['saleChannel'] ?? null,
            'customer_type' => $validated['customerType'] ?? null,
            'customer_name' => $validated['customerName'] ?? null,
            'customer_reference' => $validated['customerReference'] ?? null,
            'currency_code' => $validated['currencyCode'] ?? null,
            'sold_at' => $validated['soldAt'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'line_items' => array_map(function (array $lineItem): array {
                return [
                    'item_type' => $lineItem['itemType'] ?? null,
                    'item_reference' => $lineItem['itemReference'] ?? null,
                    'item_code' => $lineItem['itemCode'] ?? null,
                    'item_name' => $lineItem['itemName'],
                    'quantity' => $lineItem['quantity'],
                    'unit_price' => $lineItem['unitPrice'],
                    'discount_amount' => $lineItem['discountAmount'] ?? 0,
                    'tax_amount' => $lineItem['taxAmount'] ?? 0,
                    'notes' => $lineItem['notes'] ?? null,
                    'metadata' => $lineItem['metadata'] ?? null,
                ];
            }, $validated['lineItems']),
            'payments' => array_map(function (array $payment): array {
                return [
                    'payment_method' => $payment['paymentMethod'],
                    'amount' => $payment['amount'],
                    'payment_reference' => $payment['paymentReference'] ?? null,
                    'paid_at' => $payment['paidAt'] ?? null,
                    'note' => $payment['note'] ?? null,
                    'metadata' => $payment['metadata'] ?? null,
                ];
            }, $validated['payments']),
        ];
    }

    private function toVoidSalePayload(array $validated): array
    {
        return [
            'reason_code' => $validated['reasonCode'],
            'notes' => $validated['note'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ];
    }

    private function toRefundSalePayload(array $validated): array
    {
        return [
            'pos_register_id' => $validated['registerId'],
            'payment_method' => $validated['refundMethod'],
            'adjustment_reference' => $validated['refundReference'] ?? null,
            'reason_code' => $validated['reasonCode'],
            'notes' => $validated['note'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ];
    }

    private function toCafeteriaMenuItemPayload(array $validated): array
    {
        $fieldMap = [
            'itemCode' => 'item_code',
            'itemName' => 'item_name',
            'category' => 'category',
            'unitLabel' => 'unit_label',
            'unitPrice' => 'unit_price',
            'taxRatePercent' => 'tax_rate_percent',
            'status' => 'status',
            'statusReason' => 'status_reason',
            'sortOrder' => 'sort_order',
            'description' => 'description',
            'metadata' => 'metadata',
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

    private function toPharmacyOtcSalePayload(array $validated): array
    {
        return [
            'pos_register_id' => $validated['registerId'],
            'patient_id' => $validated['patientId'] ?? null,
            'customer_name' => $validated['customerName'] ?? null,
            'customer_reference' => $validated['customerReference'] ?? null,
            'currency_code' => $validated['currencyCode'] ?? null,
            'sold_at' => $validated['soldAt'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'items' => array_map(function (array $item): array {
                return [
                    'catalog_item_id' => $item['catalogItemId'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unitPrice'] ?? null,
                    'discount_amount' => $item['discountAmount'] ?? 0,
                    'tax_amount' => $item['taxAmount'] ?? 0,
                    'notes' => $item['notes'] ?? null,
                ];
            }, $validated['items']),
            'payments' => array_map(function (array $payment): array {
                return [
                    'payment_method' => $payment['paymentMethod'],
                    'amount' => $payment['amount'],
                    'payment_reference' => $payment['paymentReference'] ?? null,
                    'paid_at' => $payment['paidAt'] ?? null,
                    'note' => $payment['note'] ?? null,
                    'metadata' => $payment['metadata'] ?? null,
                ];
            }, $validated['payments']),
        ];
    }

    private function toLabQuickSalePayload(array $validated): array
    {
        return [
            'pos_register_id' => $validated['registerId'],
            'currency_code' => $validated['currencyCode'] ?? null,
            'sold_at' => $validated['soldAt'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'items' => array_map(function (array $item): array {
                return [
                    'order_id' => $item['orderId'],
                    'note' => $item['note'] ?? null,
                ];
            }, $validated['items']),
            'payments' => array_map(function (array $payment): array {
                return [
                    'payment_method' => $payment['paymentMethod'],
                    'amount' => $payment['amount'],
                    'payment_reference' => $payment['paymentReference'] ?? null,
                    'paid_at' => $payment['paidAt'] ?? null,
                    'note' => $payment['note'] ?? null,
                    'metadata' => $payment['metadata'] ?? null,
                ];
            }, $validated['payments']),
        ];
    }

    private function toFrontdeskQuickSalePayload(array $validated): array
    {
        return [
            'pos_register_id' => $validated['registerId'],
            'currency_code' => $validated['currencyCode'] ?? null,
            'sold_at' => $validated['soldAt'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'create_invoice' => $validated['createInvoice'] ?? false,
            'items' => array_map(function (array $item): array {
                return [
                    'kind' => $item['kind'],
                    'order_id' => $item['orderId'],
                    'note' => $item['note'] ?? null,
                ];
            }, $validated['items']),
            'payments' => array_map(function (array $payment): array {
                return [
                    'payment_method' => $payment['paymentMethod'],
                    'amount' => $payment['amount'],
                    'payment_reference' => $payment['paymentReference'] ?? null,
                    'paid_at' => $payment['paidAt'] ?? null,
                    'note' => $payment['note'] ?? null,
                    'metadata' => $payment['metadata'] ?? null,
                ];
            }, $validated['payments']),
        ];
    }

    private function toCafeteriaSalePayload(array $validated): array
    {
        return [
            'pos_register_id' => $validated['registerId'],
            'customer_name' => $validated['customerName'] ?? null,
            'customer_reference' => $validated['customerReference'] ?? null,
            'currency_code' => $validated['currencyCode'] ?? null,
            'sold_at' => $validated['soldAt'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'items' => array_map(function (array $item): array {
                return [
                    'menu_item_id' => $item['menuItemId'],
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'] ?? null,
                ];
            }, $validated['items']),
            'payments' => array_map(function (array $payment): array {
                return [
                    'payment_method' => $payment['paymentMethod'],
                    'amount' => $payment['amount'],
                    'payment_reference' => $payment['paymentReference'] ?? null,
                    'paid_at' => $payment['paidAt'] ?? null,
                    'note' => $payment['note'] ?? null,
                    'metadata' => $payment['metadata'] ?? null,
                ];
            }, $validated['payments']),
        ];
    }
}
