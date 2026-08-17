# Laboratory Workspace — Patient Flow Plan

**Date:** 2026-08-16
**Scope:** Laboratory (workspace 4 of the build), plus two gaps it exposes in the
workspaces already shipped.
**Status:** all decisions taken — no phase waits on an answer.

Build order: `reception → nursing → clinician → **laboratory (now)** → radiology,
pharmacy, cashier (not yet built)`.

Baselines at time of writing: backend **534 passing / 23 pre-existing failures**;
frontend **193 passing / 6 pre-existing**.

---

## 1. Where the four built workspaces stand

Re-verified in the codebase, not recalled.

| Workspace | Records flow | Live sync | Shared badge mapping | Activity tab |
|---|---|---|---|---|
| Reception | yes | yes | yes | yes |
| Nursing | yes | yes | yes | route only |
| Clinician | yes | yes | yes | yes |
| **Laboratory** | **no** | **no** | **no** | route only |

Workspace file counts: reception 35, nursing 24, clinician 17, laboratory 9 —
radiology, pharmacy and cashier **0**.

Laboratory is the only built workspace that is invisible to flow.

---

## 2. What already exists — do not rebuild it

| Already built | What it gives you |
|---|---|
| `PatientFlowStep` | Has `waiting_lab`, `in_lab`, `waiting_imaging`, `in_imaging`, both combined variants and `waiting_pharmacy`. No new steps needed. |
| `ResolveConsultationDiagnosticStepsUseCase` | Maps open orders to those steps with real precedence — waiting beats in-progress, lab kept distinct from imaging. |
| `LaboratoryOrderCompleted` | Fires on completion and already refreshes every board. |
| `laboratory/patients/{id}/flow-timeline` | Route registered; needs only a UI. |
| `LaboratoryOrderStatus` | `ordered` → waiting, `collected`/`in_progress` → in lab, `completed`/`cancelled` → closed. Maps cleanly; no enum change. |

---

## 3. Decisions taken

### Decision 1 — verification moves the patient

When lab results are verified, Laboratory records a step back to
`waiting_clinician_review` — **but only when no order on that visit is still
open**.

`waiting_clinician_review` is a queue state, not a contact state
(`isActiveContact()` is false), so the transition claims the blocking work is
finished, not that the patient is at the doctor's door. Only the lab knows that.

The failure modes are asymmetric:

- **Moved too early** → a doctor calls an empty corridor. Visible,
  self-correcting, costs a minute.
- **Never moved** → the patient sits in `in_lab` with finished results forever.
  Silent, because the board says the lab still has them.

Every invisible-step defect found in this system came from trusting a human to
remember a bookkeeping action. Prefer the noisy failure.

### Decision 2 — the lab declares, the clinician admits

Laboratory may **never** write `with_clinician`. That step stays reachable only
through the doctor's explicit **Call Patient In**. The lab moves patients between
queues; only a human puts someone in a room.

### Decision 3 — one resolver, reused at write time

The step written on any lab transition comes from
`ResolveConsultationDiagnosticStepsUseCase` — the same code the board reads,
never a second rule. It already handles the case that will otherwise bite: a
visit with three orders must not move when one completes.

---

## 4. The five phases

### Phase 1 — Stop losing patients who are in the lab

**Board is wrong today. Read-side only, two files.**

Lab steps resolve only inside the `IN_CONSULTATION` branch. But the system
explicitly supports a doctor ordering labs and releasing the patient —
`updateProviderWorkflow` preserves `consultation_started_at` for exactly that,
commented *"sent out for labs, will return"*.

So reception and clinician boards read **"Waiting for Doctor Review"** while the
patient is standing in the lab.

```php
if ($appointment->status === WAITING_PROVIDER) {
    // never consults $consultationStep
    return $consultation_started_at !== null
        ? ['waiting_clinician_review', null]
        : ['waiting_clinician', null];
}
// IN_CONSULTATION only:
$step = $consultationStep['step'] ?? 'with_clinician';
```

**Do:**

- Resolve the diagnostic step for `waiting_provider` as well as in-consultation.
  With open orders the patient reads `in_lab`; with none, they fall through to
  `waiting_clinician_review` — a truer meaning of that step anyway.
- Same change in the reception queue, which skips the resolver on the same
  condition.

**Files:**

- `app/Modules/PatientFlow/Application/UseCases/GetActiveVisitJourneyUseCase.php`
  — `deriveAppointmentStep()`
- `app/Modules/Reception/Application/UseCases/GetReceptionQueueUseCase.php` —
  the `stage === IN_CONSULTATION` guard

**Watch:** a nursing claim outranks a lab step. Someone physically with the
patient beats where they are queued.

---

### Phase 2 — Record lab steps instead of only deriving them

**Core.**

Laboratory records zero flow events, so no lab work reaches the Activity
timeline — no specimen collected, no result entered, no verification, no
attribution of who did any of it.

**Do:**

- Record through `RecordPatientFlowTransitionService` — the one door every other
  module uses.
- `ordered → collected` writes the resolved step. Testing-started and result
  entry use `allowSameStep: true`, the mechanism vitals already uses for dated
  work that moves nobody.
- Verification applies **Decision 1**: resolve across all open orders, move only
  when none remain.
- Add source labels: `laboratory.specimen_collected`,
  `laboratory.testing_started`, `laboratory.result_entered`,
  `laboratory.result_verified`.

| Lab status change | Step recorded | Source |
|---|---|---|
| `ordered` → `collected` | resolved step (`in_lab`) | `laboratory.specimen_collected` |
| `collected` → `in_progress` | same step, event only | `laboratory.testing_started` |
| result entered | same step, event only | `laboratory.result_entered` |
| verified / released | see Decision 1 | `laboratory.result_verified` |

**Files:**

- `app/Modules/Laboratory/Application/UseCases/UpdateLaboratoryOrderStatusUseCase.php`
  and the verify path
- `app/Modules/PatientFlow/Presentation/Http/Transformers/PatientFlowTimelineEntryResponseTransformer.php`
  — labels

**The multi-order trap:** the step belongs to the *visit*, not the order.
Completing one of three labs must not move the patient. Reuse the resolver at
write time rather than writing a second rule that will drift.

---

### Phase 3 — Refresh other boards on more than completion

**Mostly falls out of Phase 2.**

`LaboratoryOrderCompleted` is Laboratory's only domain event and fires only on
`completed`. Accessioning, result entry and verification — the three tabs the
workspace is built around — broadcast nothing, so reception, nursing and
clinician don't refresh until the order finishes.

**Do:**

- Recording a transition already broadcasts, so Phase 2 covers most of this.
- Add a status-changed event only where a change moves no step but the other
  three workspaces still need to know.

---

### Phase 4 — Make the Laboratory workspace flow-aware

**Frontend, independent of the recording work.**

The workspace has no live sync and no shared badge mapping, so it neither shows
where a patient is nor learns when anything changes elsewhere.

**Do:**

- `usePatientFlowLiveSync` in `laboratory/Index.vue`.
- `stepLabelKey` / `stepBadgeStatus` on the queue rows and the order header —
  never a local rule.
- Activity tab using the flow-timeline route that already exists.
- **Add the six missing labels:** `waiting_lab`, `in_lab`, `waiting_imaging`,
  `in_imaging` and the two combined variants have badge *colours* in the shared
  mapping but no label keys, so those rows currently fall through to a generic
  badge.

**Files:**

- `resources/ts/pages/laboratory/Index.vue`
- `resources/ts/pages/laboratory/components/LabQueuePanel.vue`,
  `LabOrderHeader.vue`
- `resources/ts/composables/patientFlowStep.ts` — the six labels
- `resources/ts/i18n/locales/{en,sw}/common.json`

**Also:** add `LAB.STAFF` to the workspace access-matrix guard. It covers only
reception, clinician and nursing — and has found a real role gap every single
time it was widened.

---

### Phase 5 — Two transitions in the built workspaces that record nothing

**Ungoverned write. Nursing + clinician.**

Both are reachable from workspaces already shipped, so they belong to this pass
rather than a later one.

**Do:**

- **Admission** writes no flow event, so `admitted` is unreachable — a patient
  admitted from the nursing or clinician workspace simply stops moving on the
  board.
- **Return to reception** writes `appointments.status` with raw Eloquent,
  bypassing the transition guard and the audit row, and records no flow event —
  so `returned_to_reception` is unreachable too. Route it through
  `UpdateAppointmentStatusUseCase`, the same fix already applied to the vitals
  path.
- **Consultation takeover has no dialog.** The 409 is handled and
  `handleConfirmTakeover` exists, but nothing renders a confirmation, so a doctor
  cannot take over a colleague's consultation from the UI.

**Files:**

- `app/Modules/ServiceRequest/Presentation/Http/Controllers/NurseQueueController.php`
  — `returnToReception()`
- `app/Modules/Admission/Presentation/Http/Controllers/NursingAdmissionController.php`
- `resources/ts/pages/clinician/Index.vue` — `takeoverPrompt`

---

## 5. Order of work

Two tracks, one hard dependency.

1. **Ship Phase 1 alone and first.** Read-only, two files, and it stops the
   reception and clinician boards misreporting patients who are in the lab right
   now. Nothing else here changes what a user sees as immediately.
2. **Phase 4 runs in parallel** — pure frontend wiring against infrastructure
   that already exists.
3. **Phase 2 is the spine**, and Phase 3 collapses into it. Scope Phase 3 only
   after Phase 2 lands.
4. **Phase 5** is independent and can slot anywhere.

**Done means:** a patient sent for labs is visible as `in_lab` on every built
workspace, their lab work appears on the Activity timeline with who did it, and
the Laboratory workspace refreshes live like the other three.

---

## 6. Deliberately out of scope

**Radiology, Pharmacy and Cashier.** Zero frontend files — those workspaces have
not been built. Their modules record no flow events either, and that is correct
to leave until their turn in the build order. The step vocabulary already
reserves `waiting_imaging`, `in_imaging` and `waiting_pharmacy` for them.

**Direct-service lab walk-ins** — a patient going straight to the lab with no
appointment — already flow through `ServiceRequest` as `waiting_direct_service` /
`in_direct_service`. A separate path, worth confirming on its own rather than
folding in here.

---

## Sources

Re-audited against: `PatientFlowStep`, `RecordPatientFlowTransitionService`,
`ResolveConsultationDiagnosticStepsUseCase`, `GetActiveVisitJourneyUseCase`,
`GetReceptionQueueUseCase`, `PatientFlowServiceProvider`,
`UpdateLaboratoryOrderStatusUseCase`, `LaboratoryOrderStatus`, the Admission and
ServiceRequest controllers, the four built workspace frontends, and both RBAC
guards.

No code changes were made for this plan.
