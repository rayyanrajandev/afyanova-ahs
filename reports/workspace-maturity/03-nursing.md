# Nursing — 31 / 100

**Verdict:** the lowest-scoring workspace and the one carrying the highest clinical risk.
A patient checked in correctly, appeared on the nursing worklist, and then **silently
vanished from it** because a doctor saved a draft note. Nothing errored. Nothing logged.
No test failed.

This tier is not failing because of missing features. It is failing because it answers a
patient-safety question — *"is this patient still my responsibility?"* — with a raw string
literal compared against a column that means something else.

See `00-rubric-and-scores.md` for the rubric and cross-cutting findings C1–C3.

| Dimension | Weight | At audit | Now |
|---|---:|---:|---:|
| Domain modeling & state integrity | 20 | 8 | **14** |
| Architectural conformance | 20 | 8 | **18** |
| Failure semantics & resilience | 15 | 6 | **12** |
| Test assurance | 25 | 4 | **22** |
| Observability & operability | 15 | 3 | 3 |
| Concurrency & consistency | 5 | 2 | **4** |
| **Total** | **100** | **31** | **73** |

---

## The incident, from the live database

| Time | Actor | Event |
|---|---|---|
| 15:18:09 | Receptionist (18) | Check-in → appointment `waiting_triage`, encounter created `opened` |
| 15:42:57 | Dr. Clinician (20) | Saved a **draft** medical record on that encounter |
| 15:51:31 | Dr. Clinician (20) | Encounter auto-promoted `opened` → `in_progress` |

Measured against the real table:

```
nursing filter as written   ['opened']                          -> 0 rows
widened                     ['opened','in_progress','ready_for_sign'] -> 1 rows
```

The patient was visible for 33 minutes, then disappeared. **No signal of any kind was
produced.** A nurse would have to notice the absence of a patient they were never told to
expect.

---

## Deductions

### D1 — Domain modeling: 8/20 (−12) · the root cause

`encounters.status` is a **clinical documentation lifecycle**
(`opened → in_progress → ready_for_sign → signed → amended → closed`). Nursing uses it as a
**work-queue membership predicate**. Two unrelated questions, one column.

`NurseQueueController:29` — `->where('encounters.status', 'opened')`

Three compounding problems:

1. **Raw literal.** `EncounterStatus::OPENED` exists and is not used here. The literal is
   what allowed the drift to go unnoticed.
2. **Exact match, not a set.** Every other consumer uses a *set*. `ClinicianQueueStage:70-74`
   defines `LIVE_ENCOUNTER_STATUSES = [OPENED, IN_PROGRESS, READY_FOR_SIGN]` — the correct
   vocabulary already exists, one module away.
3. **Wrong question entirely.** Even the widened set is document state. Nursing should ask a
   patient-flow question. `PatientFlowStep` — including `WITH_NURSE`, added specifically
   because "nursing had no picked-up state at all" — is the right vocabulary and nursing does
   not read it for queue membership.

**This is a known, diagnosed problem.** `CanonicalEncounterStateResolver` exists precisely to
replace legacy `encounters.status`, describes itself as "Shadow Mode", and refers to
`$legacyStatus` throughout. It is wired into `EncounterController` only. **No queue reads it.**

> **Governance gap.** That resolver cites `reports/encounter-state-machine-design/00-...md`
> and `01-...md` as its authority. Verified: the directory does not exist. The canonical
> model's rationale is unavailable to anyone maintaining it.

### D2 — Architectural conformance: 8/20 (−12)

`NurseQueueController` is **473 lines across 9 public/private methods**, and is the only
workspace controller that bypasses the architecture every other module follows:

- **Direct Eloquent writes from the controller** — `$encounter->update(['status' => 'cancelled'])`
  at lines 274 and 323; `$arrivalEvent->update(...)` at 339.
- **Inline fully-qualified class names in method bodies** — lines 250, 262, 267, 285, 301,
  312, 318, 327, 343. No imports, no injection.
- **No use cases** for `returnToReception`, `activeVisit`, or the visit-note CRUD.

For contrast, Revenue exposes 18 use cases behind controllers of 81–169 lines.

*Credit where due:* the batch-loading in `index()` is deliberate, commented, and correctly
avoids N+1 across appointments, arrival modes and insurance. The care is real — it is
applied to performance and not to correctness.

### D3 — Failure semantics: 6/15 (−9)

The same defect at `:320` means **`returnToReception` silently fails to close an
`in_progress` encounter.** The `if ($encounter !== null)` guard passes over the miss without
comment, so a patient returned to reception can leave a live encounter behind — and the
method reports success.

`:194` (`activeVisit`) carries the same predicate, so the nursing patient header loses its
visit context on the same trigger.

**All three known sites fail silently and report success.**

### D4 — Test assurance: 4/25 (−21) · the largest single deduction in this report

One test file: `tests/Feature/Nursing/ConsultationGateIntegrityTest.php`. Its three tests
cover the **appointment payment gate**, not the nursing queue:

```
it('will not let a clinician start a consultation on an unpaid visit')
it('will not let the provider workflow move an unpaid visit along')
it('keeps in_consultation unreachable from awaiting_payment in the state machine itself')
```

There is **no test asserting that a patient appears on the nursing worklist at all** — the
workspace's most basic invariant. Revenue has 25 test files. The coverage is inverted
relative to clinical risk: a nurse losing a patient off a worklist is a higher-severity
event than a mispriced charge.

### D5 — Observability: 3/15 (−12) · D6 — Concurrency: 2/5 (−3)

Cross-cutting finding C2. No signal when the worklist empties. Two nurses claiming the same
patient is untested; `nursing_contact_user_id` has no documented conflict rule.

---

## Progress

**G1, G2 and G4 are done. G3 is blocked and G5 is not started.** Nursing remains the
lowest-scoring tier, for reasons it can state precisely.

**G1 — stop the bleeding.** `EncounterStatus::liveStatuses()` is now a named domain concept
used at all three sites, and `ClinicianQueueStage` delegates to it rather than keeping a
second copy. The incident patient reappeared on the worklist immediately.

One deviation from the plan, deliberately: the third site *cancels* what it finds, and
widening it naively would have discarded `ready_for_sign` encounters — finalised
documentation awaiting signature. A second named concept, `carriesFinalisedDocumentation()`,
guards that. Handing a patient back to reception is not authority to destroy completed
clinical work, and the old code's silence was resolving that question by accident.

**G2 — architectural conformance.** `NurseQueueController` went from **473 lines to 144**,
with zero `::query()` calls, zero direct writes and zero inline fully-qualified class names.
Five classes extracted. Two deduplications fell out: the worklist and the patient header now
share one context resolver, so a nurse cannot see one stage on the row they clicked and a
different one in the header that opens; and the visit-note format `[HH:MM Author]: text`,
previously written out twice, is defined once.

`routes/api-workspaces.php` had asserted since 2026-08-16 that *"NurseVisitNotesApiTest
still covers it"*. That test did not exist. It does now, written **before** the refactor was
trusted — moving untested code quietly is how the original defect got in.

**G4 — test assurance.** **1 file / 3 tests → 6 files / 83 tests**, and the original three
were about the payment gate rather than nursing at all. The RBAC matrix asserts all 19
routes in both directions: a test that only checks 403 stays green if someone locks a route
down so hard that nurses cannot use it either. A further test asserts the nursing role
actually *holds* every permission its own routes demand.

### Found by operating the system, not planned here

- **`hasRecordedTriageVitals`** inferred "vitals taken" from the appointment status, which
  under the prepaid gate never advances for an unpaid visit — so a nurse who had just
  recorded observations was offered "Retake Vitals". Now a server-resolved fact scoped to
  the appointment, which required making the vital-set → visit linkage server-owned: the
  workspace posts only a `patientId`, so every set it had ever recorded carried
  `appointment_id NULL`.
- **The vitals timeline entry was silently dropped** for any visit that could not advance,
  because one query answered both *which visit* and *may it advance*. Splitting them
  surfaced a second case nobody was looking for: observations on a patient already with a
  doctor were being dropped too.
- **A deferred triage handoff was never resumed.** Vitals taken while unpaid correctly did
  not advance the visit; payment then promoted it to `waiting_triage` and nothing finished
  the job. The visit sat there with vitals on file while every queue asked for them.
  Completing it is now part of what payment does.

### Why it is still 73

**G3** — decoupling queue membership from documentation state — is blocked. It depends on
`reports/encounter-state-machine-design/00-*.md` and `01-*.md`, which the resolver cites as
its authority and which are **not in this repository**. Re-deriving a canonical state machine
from an implementation whose rationale was lost is how the original drift happened; the
documents should be recovered first.

**G5** (telemetry) is not started, which is why observability has not moved from 3.

---

## Goals to reach 100

### G1 — Stop the bleeding, with a test that pins it (+9 of D3, +6 of D1) · ~3h · **do first**

**Goal.** No patient disappears from the nursing worklist while their visit is live.

**Do.** Add `EncounterStatus::liveStatuses(): array` as a **named domain concept** — not an
inline array — returning `[OPENED, IN_PROGRESS, READY_FOR_SIGN]`. Use it at
`NurseQueueController` `:29`, `:194` and `:320`. Refactor `ClinicianQueueStage::LIVE_ENCOUNTER_STATUSES`
to delegate to it so one definition serves both.

**Acceptance.** A feature test — *a patient with a draft clinical note remains visible to
nursing* — reproduces the incident above and fails against today's code.

> This is a **stopgap**, and should be committed described as one. It widens the predicate;
> it does not stop nursing from asking a documentation question. G3 is the real fix.

### G2 — Restore architectural conformance (+12) · ~2d

**Goal.** Nursing obeys the same rules as every other module.

**Do.** Extract `ListNursingWorklistUseCase`, `GetActiveVisitContextUseCase`,
`ReturnPatientToReceptionUseCase` and the visit-note operations into
`ServiceRequest/Application/UseCases`, against repository interfaces. Move all writes out of
the controller. Replace the nine inline FQCNs with constructor injection. Target: controller
under 150 lines, zero `::query()` calls.

**Acceptance.** `grep -n "::query()\|->update(" NurseQueueController.php` returns nothing.

### G3 — Decouple queue membership from document state (+6 of D1, +3 of D6) · ~3d

**Goal.** Nursing asks a patient-flow question, not a documentation question.

**Do.** Land the read path of the canonical state machine for queue membership so nursing
reads patient-flow state. **Restore the two missing design documents first** — do not
re-derive a canonical model from an implementation whose rationale was lost. Define the
conflict rule for two nurses claiming one patient and pin it with an interleaved test.

**Acceptance.** No queue predicate in nursing references `encounters.status`. Adding a state
to `EncounterStatus` cannot change worklist membership.

### G4 — Test assurance to parity (+15 remaining of D4) · ~2d

**Goal.** Coverage proportionate to clinical risk, not to proximity to money.

**Do.** Build a nursing feature suite: worklist membership across every encounter and
appointment state; assessment completion removes a patient; `returnToReception` closes the
encounter **and fails loudly if it cannot**; visit-context resolution; RBAC on every route.
Target ≥ 8 test files.

**Acceptance.** Each of D1/D3's three sites has a test that fails when reverted.

### G5 — Telemetry (+12) · shares work with `01-revenue-cashier.md` G2

**Goal.** A silently emptying worklist is detectable.

**Do.** Emit `nursing.worklist_size`, and `encounter.close_failed` from `returnToReception`
when the encounter cannot be resolved — the miss that is currently swallowed.

**Acceptance.** An alert fires when an open appointment has no corresponding nursing
worklist entry.

---

## Sequencing

```
G1 (3h — stops patient loss today)
  └─> G4 (tests, in parallel with G2)
        └─> G2 (use cases)
              └─> G3 (decouple; needs the recovered design docs)
                    └─> G5 (telemetry)
```

**31 → 46** after G1. **31 → 100** after all five. Estimated total: ~7 engineer-days —
the largest investment of the three tiers, and the one with the highest patient-safety
return per day spent.
