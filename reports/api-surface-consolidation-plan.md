# One Door Per Job: Consolidating the API Surface

**Date:** 2026-08-17
**Scope:** the split between workspace-scoped routes and the older generic ones.
**Status:** all decisions taken — no phase waits on an answer.

Companion to `authorization-and-next-workspaces-plan.md`. That plan asks *"does
every door have a key?"*. This one asks *"why does the same room have two
doors?"* — and it turns out to be the reason the first plan has findings at all.

---

## 1. The problem in one line

**The product calls one set of routes. The tests exercise a different set.**

| | Workspace-scoped | Legacy / generic |
|---|---|---|
| Routes registered (`api/v1`) | 257 | 485 |
| **Call sites in tests** | **546** | **2260** |

81% of every test call in this repo goes through the older generic routes. But
the four built workspaces — reception, nursing, clinician, laboratory — call the
workspace-scoped ones, by standing project rule.

So the routes staff actually use are thinly tested, and the routes that are
heavily tested are largely not the ones the product calls.

---

## 2. This is not theoretical — it already shipped two bugs

**48 controller actions are served by more than one route.** Of those, **7 have
routes that disagree about which ability guards them.** Two are real defects,
and both are ones we have already met.

### 2.1 The clinician note-saving bug, explained

`authorization-and-next-workspaces-plan.md` flags this as Phase 0. Here is the
mechanism:

```
MedicalRecordController@update
  api/v1/medical-records/{id}            can:medical.records.draft.update   ← has a Gate. works.
  api/v1/clinician/medical-records/{id}  can:medical.records.update         ← granted to NO role.
```

Same controller action. Two doors. **The legacy door works and is well tested.
The workspace door is broken — and it is the one the frontend calls**
(`useClinicianEncounter.ts:348`).

That is the whole failure mode of this document in four lines.

### 2.2 A privilege widened by accident

```
LaboratoryOrderController@auditLogs
  api/v1/laboratory-orders/{id}/audit-logs   can:laboratory.orders.audit-logs.view
  api/v1/laboratory/orders/{id}/audit-logs   can:laboratory.orders.read
```

`LAB.SUPERVISOR` holds the audit permission; `LAB.STAFF` holds only read. So a
bench technologist **cannot** read audit logs through the old door and **can**
through the new one. The dedicated audit permission is bypassed by walking in
the other entrance. Nobody decided this.

### 2.3 The laboratory workspace outage, same shape

Already fixed, recorded here because it is the third instance: the workspace
copies of the lab's action routes were gated on `laboratory.orders.update-status`
(no holder) while the legacy copies used `lab.sample.collect` (correctly
granted). Lab staff could read their worklist and not do their job.

**Three bugs, one cause.** Duplicated routes drift apart, and the tests watch
the wrong copy.

### 2.4 The other five disagreements are fine

Stated so nobody "fixes" them: `patientTimeline` scoped per workspace,
`wardBeds` split ward/platform-admin, `updateStatus` split sign/status,
`departmentOptions`, and the medication catalog split prescriber/pharmacy. These
are **deliberate** — different audiences, different abilities. Leave them.

---

## 3. Decisions taken

### Decision 1 — the workspace route is the real one

Where a job has both doors, the workspace-scoped route is authoritative. It is
what the product calls, it carries the workspace's own authorization, and it is
already the standing rule for frontend code. The generic route is the copy.

### Decision 2 — tests follow the product, not the other way round

A test that exercises a route the product never calls is not protecting the
product. Where a workspace route exists, tests for that behaviour move onto it.
The legacy route keeps only the tests that prove *it* still works for whatever
still calls it.

This is a real gap in my own recent work: the laboratory flow-recording tests
call `/api/v1/laboratory-orders/...`. Same controller, so the recording logic is
genuinely covered — but they would not have caught the outage in 2.3.

### Decision 3 — duplicates are a guard, not a cleanup

Deleting 485 legacy routes is not on the table; too much still calls them. What
is on the table is making a *new* duplicate impossible to add silently. A guard
asserts that where one controller action serves two routes, either the abilities
match or the pair is on an explicit allow-list with a stated reason (§2.4).

Same shape as every guard that has earned its place here: it does not forbid the
thing, it forbids doing it *by accident*.

---

## 4. The phases

### Phase 1 — Guard the duplicates

**The deliverable. One test.**

Walk the route table, group by controller action + method, and fail when two
routes carry different `can:` abilities unless the pair is listed with a reason.
Seed the allow-list with the five deliberate splits in §2.4.

Run it and it fails immediately on §2.1 and §2.2 — which is the point.

**Files:** `tests/Feature/Platform/RouteAuthorizationContractTest.php`

---

### Phase 2 — Fix the two real disagreements

- **`medical.records.update`** — grant it, or point the workspace route at
  `medical.records.draft.update` like its twin. Prefer pointing it at the twin:
  that ability has a working Gate and is already proven.
- **Lab audit logs** — point the workspace route at
  `laboratory.orders.audit-logs.view`, closing the accidental widening.

Both are one-line route changes. Phase 1 is what stops the next one.

---

### Phase 3 — Move the built workspaces' tests onto their own routes

**Highest value per hour, and it is mechanical.**

Only **one** test file currently exercises workspace-prefixed laboratory routes,
and it is the access-matrix guard. Everything else tests the legacy twin.

Order by what is already shipped:

1. **Laboratory** — including my own `LaboratoryPatientFlowRecordingTest`
   (Decision 2).
2. **Clinician** — the module with a live bug the legacy tests could not see.
3. **Nursing**, then **Reception**.

Do not migrate tests for routes with no workspace equivalent. This is not a
sweep of all 2260 call sites; it is the subset that has a workspace twin.

---

### Phase 4 — Make it the default for radiology and pharmacy

When those workspaces are built, their tests target `radiology/*` and
`pharmacy/*` from the first commit. Phase 1's guard plus the access matrix means
a new workspace route that nobody can reach fails the suite the day it is
written, rather than after it ships.

No migration needed if it is never split in the first place.

---

## 5. Order of work

1. **Phase 1** — the guard. Everything else is its output.
2. **Phase 2** — the two fixes it reports. `medical.records.update` overlaps
   Phase 0 of the authorization plan; do it once, in whichever lands first.
3. **Phase 3** — laboratory and clinician first.
4. **Phase 4** — free, if the habit starts with radiology.

**Done means:** no controller action is reachable through two doors with
different locks unless someone wrote down why; and every built workspace's tests
call the same routes its screens do.

---

## 6. Deliberately out of scope

**Deleting the 485 legacy routes.** Too much still depends on them, and the
guard removes the danger without the risk. Revisit when a module's legacy
surface has no callers left.

**The 2260 legacy test call sites as a whole.** Only the subset with a workspace
twin matters. The rest are testing the only door there is.

**Renaming abilities.** Tempting while in here. It is a separate change with a
separate blast radius, and `authorization-and-next-workspaces-plan.md` owns it.

---

## Sources

Measured against `php artisan route:list --json` (742 `api/v1` routes,
2026-08-17), all `can:` middleware on those routes, all 35 roles in
`config/roles.php`, the live `permission_role` table, every `/api/v1/` string in
`tests/`, and `useClinicianEncounter.ts`.

No code was changed for this plan.
