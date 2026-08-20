# Workspace Maturity Audit — Rubric and Scores

**Date:** 2026-08-19
**Scope:** Reception, Nursing, Clinician, Laboratory, Radiology, Pharmacy, Clinical Procedure, Cashier
**Method:** static audit of `app/Modules/*` plus live queries against the `afyanova-ahs-v2` database.
**Status:** assessment only — no production code was changed to produce this report.

---

## Why this document exists

An earlier pass produced impression-based scores (Revenue 85, Clinical 72, Nursing 50).
Those numbers were formed **before** the observability and failure-handling dimensions were
actually verified. This document replaces them with rubric-based scores computed from
evidence. Two of the three moved down. The reasoning is recorded per workspace so the
scores can be challenged on the evidence rather than on taste.

| Tier | Impression | **Verified** | Moved because |
|---|---|---|---|
| Revenue / Cashier | 85 | **77** | zero telemetry infrastructure confirmed; config-to-data contract untested |
| Clinical order workspaces | 72 | **58** | charge-cancellation error handling is wrong in all four modules |
| Nursing | 50 | **31** | queue predicate defect is structural, not a typo; 1 test file, none on the queue |

---

## The rubric

Six dimensions, weighted for a hospital information system where patient-safety
consequences outrank cosmetic concerns. Total 100.

| # | Dimension | Weight | What full marks means |
|---|---|---:|---|
| 1 | Domain modeling & state integrity | 20 | One vocabulary per concept, expressed as enums/VOs. No concept encoded as a raw literal. No column serving two unrelated questions. |
| 2 | Architectural conformance | 20 | Presentation stays thin; all writes flow through use cases against repository interfaces. No duplicated policy across modules. |
| 3 | Failure semantics & resilience | 15 | Every failure path is deliberate, documented, and **leaves a trace**. Fail-open vs fail-closed is chosen per consequence, not per convenience. |
| 4 | Test assurance | 25 | Mechanism **and** the configuration-to-data contract are tested. A green suite implies a working feature in a real environment. |
| 5 | Observability & operability | 15 | Business-critical silent paths emit counters/alerts, not just log lines. An operator can answer "is the gate working?" without a database console. |
| 6 | Concurrency & consistency | 5 | Races are tested, not reasoned about. Transaction boundaries explicit. |

### Why test assurance carries the largest weight

The single most consequential finding of this audit is that **a fully green test suite
coexists with a completely inert prepaid consultation gate**. Every test fabricates its
own catalog item (`CONSULT-TEST`, `CONSULT-SEQ`, `CONSULT-'.Str::random(6)`), so no test
ever exercises the code path that production actually uses. Coverage that cannot detect a
dead feature is not assurance.

---

## Scores

Two columns per tier: the score when this was written, and the score now that
remediation has landed. Arrows are the audit's own claim that its goals were
worth doing; they should be read sceptically and checked against the per-tier
documents, each of which records what was actually done and what was not.

| Dimension | Weight | Revenue / Cashier | Clinical Orders | Nursing |
|---|---:|---:|---:|---:|
| Domain modeling & state integrity | 20 | 18 → **18** | 15 → **20** | 8 → **14** |
| Architectural conformance | 20 | 19 → **19** | 15 → **20** | 8 → **18** |
| Failure semantics & resilience | 15 | 13 → **13** | 6 → **15** | 6 → **12** |
| Test assurance | 25 | 18 → **23** | 15 → **22** | 4 → **22** |
| Observability & operability | 15 | 4 → **11** | 4 → **11** | 3 → **3** |
| Concurrency & consistency | 5 | 5 → **5** | 3 → **3** | 2 → **4** |
| **Total** | **100** | **77 → 89** | **58 → 91** | **31 → 73** |

Nursing stays furthest from 100 for two stated reasons: G3 (decoupling queue
membership from documentation state) is blocked on design documents that are
missing from the repository, and G5 (telemetry) is not started. Its
observability score has not moved at all.

Revenue's observability rose to 11 rather than 15 because the signals now exist
and nothing yet acts on them: `revenue:reconcile` exits non-zero for a scheduler
to alert on, and no scheduler calls it.

---

## The four findings that cut across every workspace

These are not per-workspace defects. Fixing them once fixes them everywhere, and they
account for the majority of every deduction below 100.

C1–C3 were found by reading the code. C4 and C5 were found by operating the system once
the prepaid gate was switched on, and neither would have been found by reading — which is
itself the finding worth recording. An audit conducted entirely at rest missed a class of
defect that a single afternoon of real use surfaced five times.

### C1 — The configuration-to-data contract is untested (affects all 8 workspaces)

`config/revenue.php` declares five prepaid gates enabled. Verified live:

```
prepaid_required_for.consultation = true
chargeable_items total             = 237
chargeable_items matching CONSULT* = 0
service_charges                    = 0
```

`ConsultationChargeRaiser::resolveConsultationItem()` looks up
`config('revenue.consultation.default_item_code')` = `CONSULT-GENERAL-OPD`.
`DskChargeableItemsSeeder` seeds 237 items and **not one consultation item**. The raiser
logs and returns `null`; `ServiceAuthorization::notCharged()` returns `authorized: true`
by deliberate design; check-in routes straight past the cashier.

**The gate is off in every real environment and nothing reports it.**

### C2 — Observability is absent on every fail-open path

Verified: no Prometheus, no StatsD, no Sentry, no APM, no alerting. `config/logging.php`
is Laravel default. Zero notifications in `app/Modules/Revenue/`. The codebase does have a
telemetry concept (`AuditExportRetryResumeTelemetryEventModel` in Platform), so this is an
application gap, not a missing capability.

Fail-open is the right call in healthcare — never block care for a billing fault. Failing
open **silently** is not. Today, unbilled revenue produces a `Log::warning` nobody reads.

### C3 — One policy, many copies

`PatientFlowStep` exists because five copies of a status mapping had drifted. That lesson
has not been carried into the prepaid gate, which now exists as **four independent
implementations in three different shapes** — with four different failure behaviours.
See `02-clinical-order-workspaces.md`.

---

### C4 — A vocabulary grows; the code that enumerates it does not

Found by operating the system after the prepaid gate was armed, not by reading it.
`AWAITING_PAYMENT` was added to two enums, and **five separate places that branch on
those enums were left behind**:

| Where | How it failed |
|---|---|
| `encounters.status = 'opened'` (nursing worklist) | a patient silently vanished from a clinical work queue |
| Four writers of `waiting_provider` | the payment gate held only by accident, not by intent |
| `findActiveForPatient()` status list | Reception profile crashed; a patient could be checked in twice |
| `hasRecordedTriageVitals` negative test | "Retake Vitals" offered for vitals nobody had taken |
| `PatientFlowStep::label()` | `UnhandledMatchError` — a 500 reaching the browser |

Four of the five share one shape: **a predicate that enumerates the states it does not
mean**. `status !== 'waiting_triage'` silently absorbs every state invented after it was
written; `in_array($status, PRE_TRIAGE_STATUSES)` cannot.

Three mitigations, in the order they were adopted:

1. **Static analysis.** Larastan at level 5 reports a `match` that does not handle every
   case of a known enum — the exact failure above, by name. Verified by reintroducing the
   bug: `Match expression does not handle remaining value: PatientFlowStep::AWAITING_PAYMENT`.
   684 pre-existing findings are baselined so new ones fail while old ones wait; **no
   `match.unhandled` entries are baselined**, so no other latent enum crash is hiding.
2. **Status sets live on the enum**, never inline in a query — `EncounterStatus::liveStatuses()`,
   `AppointmentStatus::arrivedAndUnresolved()`, `ChargeSourceKind::prepaidGateEnabled()`,
   `PRE_TRIAGE_STATUSES`. Four were created during remediation, each replacing literals
   that had been retyped across several files.
3. **Per-enum exhaustiveness tests** that iterate `cases()` rather than listing them, so a
   case added tomorrow is covered the moment it exists. One exists
   (`tests/Unit/PatientFlow/PatientFlowStepTest.php`); the pattern is not yet applied to
   the other domain enums.

Static analysis catches the throwing variant. It does **not** catch a denylist that
quietly mis-classifies a new case, which is valid PHP — that is what (2) and (3) are for.

### C5 — A boolean that is false for more than one reason

`isPayable` is false both for an unpriced charge and for one already settled. The basket
rendered the two identically, so a fully paid consultation was labelled **"Not priced"**.
Harmless only while settled charges never reached that screen; surfacing them made it
visible immediately.

No linter finds this. The rule is a design one: a flag consumers must interpret should
name the reason rather than assert a verdict — `payability: 'payable' | 'unpriced' | 'settled'`
rather than `isPayable: bool`. Two other instances of the same shape were fixed during
remediation (`amountDue` meaning "outstanding" on a tab that wanted "paid", and one query
answering both *which visit* and *may it advance*).

---

## Reading order

1. `01-revenue-cashier.md` — 77 → 89
2. `02-clinical-order-workspaces.md` — 58 → 91
3. `03-nursing.md` — 31 → 73

Each states the deductions with evidence, then a **Progress** section recording what was
actually done and what was not, then the goals with acceptance criteria and sequencing.

Nine goals remain open across the three tiers. The three that matter most, in order:

1. **Nursing G3** — decouple queue membership from documentation state. Blocked on
   `reports/encounter-state-machine-design/*.md`, which are cited by
   `CanonicalEncounterStateResolver` and absent from this repository. Recovering them is
   the first step, not re-deriving the model.
2. **Schedule `revenue:reconcile`.** The telemetry exists and nothing listens to it, which
   is the same failure this audit opened with — a signal nobody reads.
3. **Apply the exhaustiveness-test pattern to the remaining domain enums.** One exists;
   the rest of C4's mitigation is unbuilt.
