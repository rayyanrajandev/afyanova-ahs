<?php

namespace App\Modules\ServiceRequest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Appointment\Presentation\Http\Transformers\AppointmentResponseTransformer;
use App\Modules\ServiceRequest\Application\Services\VisitNoteLogService;
use App\Modules\ServiceRequest\Application\UseCases\CompleteNurseAssessmentUseCase;
use App\Modules\ServiceRequest\Application\UseCases\GetActiveVisitContextUseCase;
use App\Modules\ServiceRequest\Application\UseCases\ListNursingWorklistUseCase;
use App\Modules\ServiceRequest\Application\UseCases\ReturnPatientToReceptionUseCase;
use App\Modules\ServiceRequest\Presentation\Http\Requests\CompleteNurseAssessmentRequest;
use App\Modules\ServiceRequest\Presentation\Http\Transformers\ServiceRequestResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nursing's HTTP surface.
 *
 * Thin on purpose (2026-08-19, workspace maturity audit goal G2). This was a
 * 497-line controller running its own Eloquent queries and writes, with
 * fully-qualified class names inline in method bodies — the only workspace in
 * the codebase that did not route through use cases and repository interfaces.
 * It was also the workspace that silently dropped a patient off a worklist.
 * Those two facts are related: the layer that skipped the discipline is the
 * layer that failed.
 */
class NurseQueueController extends Controller
{
    public function __construct(
        private readonly ListNursingWorklistUseCase $listWorklist,
        private readonly GetActiveVisitContextUseCase $getActiveVisit,
        private readonly ReturnPatientToReceptionUseCase $returnToReceptionUseCase,
        private readonly VisitNoteLogService $visitNotes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listWorklist->execute(
            perPage: (int) $request->input('perPage', 20),
            page: (int) $request->input('page', 1),
        ));
    }

    public function assess(
        string $encounterId,
        CompleteNurseAssessmentRequest $request,
        CompleteNurseAssessmentUseCase $useCase,
    ): JsonResponse {
        $validated = $request->validated();

        $order = $useCase->execute(
            encounterId: $encounterId,
            clinicalNote: $validated['clinicalNote'],
            items: $validated['items'],
            actorId: $request->user()?->id,
        );

        return response()->json([
            'data' => ServiceRequestResponseTransformer::transform($order),
        ], 201);
    }

    public function activeVisit(string $patientId): JsonResponse
    {
        return response()->json([
            'data' => $this->getActiveVisit->execute($patientId),
        ]);
    }

    public function returnToReception(string $appointmentId, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        $result = $this->returnToReceptionUseCase->execute(
            appointmentOrEncounterId: $appointmentId,
            reason: $request->input('reason'),
            actorId: $user?->id,
            actorName: $user?->name,
            actorFacilityId: $user?->facility_id,
        );

        abort_if($result['type'] === 'not_found', 404, 'Appointment or encounter not found.');

        return response()->json([
            'data' => $result['type'] === 'appointment'
                ? AppointmentResponseTransformer::transform($result['appointment'])
                : $result['encounter'],
        ]);
    }

    public function addVisitNote(string $appointmentId, Request $request): JsonResponse
    {
        $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        $user = $request->user();

        return $this->visitNotesResponse($this->visitNotes->append(
            $appointmentId,
            (string) $request->input('note'),
            $user?->name ?? 'Staff',
        ));
    }

    public function getVisitNotes(string $appointmentId): JsonResponse
    {
        return $this->visitNotesResponse($this->visitNotes->notesFor($appointmentId));
    }

    public function updateVisitNotes(string $appointmentId, Request $request): JsonResponse
    {
        $request->validate([
            'verificationNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->visitNotesResponse(
            $this->visitNotes->replace($appointmentId, $request->input('verificationNotes')),
        );
    }

    public function deleteVisitNote(string $appointmentId, Request $request): JsonResponse
    {
        $request->validate([
            'index' => ['required', 'integer', 'min:0'],
        ]);

        return $this->visitNotesResponse(
            $this->visitNotes->deleteLine($appointmentId, (int) $request->input('index')),
        );
    }

    private function visitNotesResponse(?string $notes): JsonResponse
    {
        return response()->json([
            'data' => ['verificationNotes' => $notes],
        ]);
    }
}
