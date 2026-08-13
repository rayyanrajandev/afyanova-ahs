<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Controllers;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementReason;
use Illuminate\Http\JsonResponse;

class InventoryStockMovementReasonController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => InventoryStockMovementReason::options(),
        ]);
    }
}
