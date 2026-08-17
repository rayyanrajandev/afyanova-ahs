# Authorization Truth Pass, Then Radiology & Pharmacy

**Date:** 2026-08-17
**Scope:** the authorization layer across all 35 roles, then workspaces 5 and 6.
**Status:** all decisions taken — no phase waits on an answer.

Build order so far: `reception → nursing → clinician → laboratory (done)` →
**authorization pass (now)** → `radiology → pharmacy → cashier`.

Baselines at time of writing: backend **1596 passing / 462 failing**; frontend
**196 passing / 6 failing**; `vue-tsc` **46 errors**. Every number below was
measured, not recalled.

---

## 1. Why this is not a feature plan

The Laboratory workspace shipped in a state where a laboratory technologist
could open it, read the worklist, and get **403 on accessioning a specimen and
on entering a result**. Its own action routes were gated on
`laboratory.orders.update-status`, an ability that **no role holds** — verified
against the live database, not just config:

```
laboratory.orders.update-status    roles in DB: NONE
lab.sample.collect                 roles in DB: CLINICAL.NURSE, LAB.STAFF, LAB.SUPERVISOR, …
```

The workspace was non-functional for the staff it was built for. Nobody caught
it, because it fails **closed and silent**: no error in a log, no failing test
anyone was reading, just a member of staff holding a patient and getting a
permission denial they cannot self-diagnose.

It was found only because `WorkspaceRoleAccessMatrixTest` was widened to include
`LAB.STAFF` — and it surfaced within seconds of that guard existing.

**This is not one bug. It is a class**, and the class is still live.

---

## 2. What the audit actually found

257 abilities guard routes. 15 have an explicit `Gate::define`. Cross-checking
the remaining ones against every role in `config/roles.php`:

> **95 of 257 route abilities (37%) are granted to no role and have no gate.**
> Nothing behind them is reachable by anybody.

By module:

| Module | Unreachable | Module | Unreachable |
|---|---|---|---|
| staff | 15 | claims | 5 |
| billing | 15 | patients | 4 |
| pos | 13 | service | 3 |
| platform | 9 | inpatient / emergency / appointments | 3 each |
| inventory | 8 | radiology | 2 |
| specialties | 5 | theatre / medical / admissions | 1 each |

And the 462 failing tests are the same story told back:

| Failure kind | Count |
|---|---|
| **403 where success expected** (200/201/422) | **169** |
| 404 where success or 403 expected | 103 |
| other assertions | 139 |

The worst-hit suites are **Pharmacy (71), Laboratory (50), Radiology (23)** —
precisely the three modules whose permissions were remapped by
`2026_07_16_000002_insert_workflow_permissions`. The migration introduced the
`lab.*` / `imaging.*` workflow permissions and moved the roles onto them. The
routes were never fully brought along.

### 2.1 One of them is in a workspace already in production use

Of the 95, exactly one sits on a route a **built** workspace calls:

```
PATCH clinician/medical-records/{id}   →   can:medical.records.update
medical.records.update                 →   roles in DB: NONE
```

`useClinicianEncounter.ts:348` PATCHes that route to save a consultation note
draft. Creating the first draft POSTs (`medical.records.create`, which *is*
granted) and succeeds; **every subsequent save of that same draft is a 403.**

Not yet reproduced end-to-end through the UI — a facility super-admin bypasses
the gate entirely (`isFacilitySuperAdmin`), which is the likeliest reason this
has gone unnoticed. **Reproduce as a plain `CLINICAL.PHYSICIAN` before anything
else in this plan.** If it confirms, it is a hotfix, not a phase.

### 2.2 There are two sources of truth for grants, and that is the root cause

Permissions reach a role from **`config/roles.php`** (pushed by
`php artisan roles:sync`) *and* from **migrations** that seed
`permission_role` directly. Neither knows about the other. A route author checks
one, a role author edits the other, and the drift is invisible until a human
hits a 403.

Every specific bug in this document is a symptom of that one structural fact.

---

## 3. Decisions taken

### Decision 1 — the guard is the deliverable, not the fixes

Fixing 95 abilities by hand fixes today. The guards that **find** them fix every
tomorrow. `WorkspaceRoleAccessMatrixTest` and `RouteAuthorizationContractTest`
already exist and have earned their keep: between them they have caught the lab
ability drift, three phantom routes, four role gaps, and the two unreachable lab
actions. Extending their coverage from 4 workspaces to all 35 roles is the
highest-leverage work available.

### Decision 2 — `config/roles.php` is the single source of truth

Migrations may create permissions in the catalog. They may **not** grant them to
roles. Anything a role holds is declared in `config/roles.php` and pushed by
`roles:sync`. A guard asserts the live `permission_role` table contains nothing
that `config/roles.php` does not declare, so the second source cannot grow back.

### Decision 3 — unreachable means delete or grant, never ignore

Each of the 95 gets exactly one of two dispositions, recorded:

- **Grant it** — the feature is real and some role should reach it.
- **Delete the route** — nothing calls it and no role should. Three lab phantom
  routes were already removed this way; `pos.*` (13) and `specialties.*` (5)
  look like the same thing.

"Leave it gated on nothing" is not a disposition. That is the current state, and
it is indistinguishable from a bug.

### Decision 4 — payment is a flow step, not a screen

For a self-pay patient in a Tanzanian private facility, the cashier sits between
the doctor and the lab. Today `Send for Diagnostics` moves the patient straight
to `waiting_lab`, and the lab can accession, run and verify with **no payment or
coverage gate anywhere in the path**. The step vocabulary has no billing step at
all (verified: zero matches for `waiting_payment`).

`waiting_payment` is added to `PatientFlowStep` **now**, while the flow layer is
fresh — not when the cashier workspace is built. Retrofitting a step after three
more workspaces read `waiting_lab` means re-cutting all of them.

---

## 4. The phases

### Phase 0 — Reproduce the clinician note-save 403

**Hotfix candidate. Do this before reading further.**

Sign in as a plain `CLINICAL.PHYSICIAN` (not a facility super-admin), open a
consultation, save a note draft twice. If the second save 403s, grant
`medical.records.update` and ship it on its own.

**Files:** `config/roles.php`, `routes/api-workspaces.php:233`

---

### Phase 1 — Extend the guards to every role

**The deliverable. Read-only until it fails.**

- `WorkspaceRoleAccessMatrixTest` covers reception, clinician, nursing,
  laboratory. Add radiology, pharmacy, billing/cashier, inpatient, and the staff
  and platform admin roles.
- Add a third guard: **every route ability is reachable by at least one role.**
  This is the check that would have caught all 95 on the day each was written.
- Add a fourth: **the live `permission_role` table matches `config/roles.php`**
  (Decision 2), so migration-seeded grants cannot silently reappear.

The laboratory entry already proved the matrix must model *seniority splits* —
`roles` is a list, and an ability need only be reachable by one of them, because
forcing verification onto `LAB.STAFF` would erase a real clinical control.

**Expect Phase 1 to fail loudly on first run. That is the point.**

**Files:** `tests/Feature/Platform/WorkspaceRoleAccessMatrixTest.php`,
`tests/Feature/Platform/RouteAuthorizationContractTest.php`

---

### Phase 2 — Disposition the 95

**Mechanical once Phase 1 names them. Order by blast radius.**

1. **radiology (2) and pharmacy** — next two builds. Do these first so the
   workspaces land on a working foundation rather than repeating Laboratory.
2. **billing (15) and pos (13)** — needed by the cashier workspace, and
   `pos.*` is the strongest delete candidate.
3. **staff (15), platform (9), inventory (8)** — admin surfaces, lower urgency.
4. The rest.

Every disposition is grant-or-delete (Decision 3).

---

### Phase 3 — Classify the 462, do not fix all 462

**The target is zero *unexplained*, not zero.**

A suite with 462 unread failures protects nothing — it is precisely how this
drift survived. Phases 1–2 should clear a large share of the 169 403s on their
own; measure again after, then split what remains into:

- **real bugs** — fix, starting with Pharmacy (71) and Radiology (23), the
  modules about to be built on;
- **tests written against an API that intentionally changed** — update;
- **dead tests** — delete.

Whatever number is left must be *known*, so the next regression is visible
against a stable baseline. Do not chase the 139 miscellaneous assertions yet.

---

### Phase 4 — Radiology workspace

**Should be cheap. Nearly all the machinery exists.**

Already built and module-agnostic:

| Exists | Gives you |
|---|---|
| `waiting_imaging`, `in_imaging`, `waiting_lab_and_imaging`, `in_lab_and_imaging` | Full step vocabulary, labels in `en` + `sw` |
| `ResolveConsultationDiagnosticStepsUseCase` | Already batches radiology orders with correct precedence |
| `ResolveVisitStagesUseCase` | One answer for the badge across every surface |
| `ClinicalOrderVisitStageEnricher` | `visitStage` on any order list |
| `usePatientFlowLiveSync`, `stepLabelKey`/`stepBadgeStatus` | Live refresh and badges |

**Do:** mirror Laboratory. `RecordRadiologyFlowTransitionService` on the same
single write door (Radiology currently records **zero** flow events — verified),
source labels, live sync, Activity tab, `RADIOLOGY.STAFF` in the matrix.

**The one genuine difference:** radiology has a scheduled state (`SCHEDULED`)
that laboratory has no equivalent of. Decide whether a scheduled study reads
`waiting_imaging` or earns its own step **before** writing the recorder.

---

### Phase 5 — Billing gate, then Pharmacy

**Pharmacy last of the three: most broken tests, and it needs the gate.**

- Add `waiting_payment` to `PatientFlowStep` (Decision 4) with labels.
- Decide the policy explicitly, and write it down: which coverage types bypass
  the gate (NHIF / insured) and which are held (self-pay). This is a clinical
  and commercial rule, not a technical one.
- Then the Pharmacy workspace, mirroring Laboratory and Radiology.

---

## 5. Order of work

1. **Phase 0 alone and first** — a doctor may be unable to save notes.
2. **Phase 1** — the guards. Everything after depends on their output.
3. **Phase 2**, radiology and pharmacy slices first.
4. **Phase 3** measure-then-classify.
5. **Phase 4** radiology; **Phase 5** billing gate, then pharmacy.

**Done means:** no route in the system is gated on an ability nobody holds; every
workspace role can perform its own workspace's actions; the failing-test count is
explained rather than merely large; and a patient sent for imaging is visible as
`in_imaging` on every built workspace.

---

## 6. Deliberately out of scope

**Cashier workspace.** Blocked on Decision 4's policy, and it is seventh in the
build order. `waiting_payment` is added now so the flow layer is ready; the
screen is not.

**The 139 miscellaneous assertion failures.** Classify in Phase 3, fix later.
They are not blocking a workspace.

**Multi-source permission cleanup beyond `permission_role`.** Decision 2's guard
stops the bleeding. Rewriting historical migrations is not worth it.

---

## Sources

Measured against: all 257 route abilities in `routes/`, the 15 `Gate::define`
declarations in `app/Providers/`, all 35 roles in `config/roles.php`, the live
`permissions` / `permission_role` tables, the full Pest suite (JUnit,
2026-08-17), `useClinicianEncounter.ts`, `PatientFlowStep`,
`ResolveConsultationDiagnosticStepsUseCase`, and the Radiology, Pharmacy and
Billing modules' flow-event usage.

No code was changed for this plan.
