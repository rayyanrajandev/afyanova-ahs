<?php

namespace App\Modules\Pharmacy\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetStockStatusReportUseCase;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetLowStockReportUseCase;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetOutOfStockReportUseCase;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetNearExpiryReportUseCase;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetExpiredReportUseCase;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetDispensedMedicinesUseCase;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetBatchTrackingUseCase;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetMedicinesByClinicianUseCase;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetControlledDrugsRegisterUseCase;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetInsuranceClaimsUseCase;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetPrescriptionTrendsUseCase;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetMedicineConsumptionUseCase;
use App\Modules\Pharmacy\Application\UseCases\Reports\GetInventoryDashboardKpisUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PharmacyReportsController extends Controller
{
    // ─── Sprint 1: Inventory Health ─────────────────────────────────

    public function stockStatus(Request $request, GetStockStatusReportUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        return response()->json($result);
    }

    public function lowStock(Request $request, GetLowStockReportUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        return response()->json($result);
    }

    public function outOfStock(Request $request, GetOutOfStockReportUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        return response()->json($result);
    }

    public function nearExpiry(Request $request, GetNearExpiryReportUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        return response()->json($result);
    }

    public function expired(Request $request, GetExpiredReportUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        return response()->json($result);
    }

    // ─── Sprint 2: Dispensing ──────────────────────────────────────

    public function dispensedMedicines(Request $request, GetDispensedMedicinesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        return response()->json($result);
    }

    public function batchTracking(Request $request, GetBatchTrackingUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        return response()->json($result);
    }

    public function medicinesByClinician(Request $request, GetMedicinesByClinicianUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        return response()->json($result);
    }

    // ─── Sprint 3: Compliance ──────────────────────────────────────

    public function controlledDrugsRegister(Request $request, GetControlledDrugsRegisterUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        return response()->json($result);
    }

    public function insuranceClaims(Request $request, GetInsuranceClaimsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        return response()->json($result);
    }

    // ─── Sprint 4: Analytics ────────────────────────────────────────

    public function prescriptionTrends(Request $request, GetPrescriptionTrendsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        return response()->json($result);
    }

    public function medicineConsumption(Request $request, GetMedicineConsumptionUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        return response()->json($result);
    }

    // ─── KPI Dashboard ─────────────────────────────────────────────

    public function dashboardKpis(Request $request, GetInventoryDashboardKpisUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute();
        return response()->json(['data' => $result]);
    }
}
