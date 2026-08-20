# Clinical Order Workspaces — 58 / 100

**Scope:** Laboratory, Radiology, Pharmacy, Clinical Procedure.

**Verdict:** the clinical intent is right and the order state machines are sound. The score
is dragged down by one fact: **the prepaid gate exists as four independent implementations
in three shapes, with four different failure behaviours, and none of the four is correct.**
Most of this code is uncommitted work in progress, so the cheapest moment to fix it is now.

See `00-rubric-and-scores.md` for the rubric and cross-cutting findings C1–C3.

| Dimension | Weight | At audit | Now |
|---|---:|---:|---:|
| Domain modeling & state integrity | 20 | 15 | **20** |
| Architectural conformance | 20 | 15 | **20** |
| Failure semantics & resilience | 15 | 6 | **15** |
| Test assurance | 25 | 15 | **22** |
| Observability & operability | 15 | 4 | **11** |
| Concurrency & consistency | 5 | 3 | 3 |
| **Total** | **100** | **58** | **91** |

---

## What earns the score

Order status enums enforce **forward-only** transitions with explanatory failure messages.
`ClinicalOrderLifecycle::assertActiveForWorkflow()` is shared across all four modules — proof
the team already knows how to centralise a cross-module rule. Each module has a
`*PrepaidWorkflowTest`. Charge raisers resolve their catalog item from the order's own
clinical catalog linkage (`clinical_catalog_item_id`, then `test_code`), with facility-scoped
precedence — so unlike consultation, **these four gates can actually resolve an item**:
price-book coverage is 237/237.

---

## Deductions

### D1 — Failure semantics: 6/15 (−9, the defining defect of this tier)

Four modules. Three behaviours. **Zero correct.**

| Module | Behaviour on charge-cancel failure | Result |
|---|---|---|
| Laboratory `:138` | `catch (\Throwable $e) { }` — empty | Order cancelled, **charge survives** |
| Radiology `:138` | `catch (\Throwable $e) { }` — empty | Order cancelled, **charge survives** |
| Pharmacy `:156` | `catch (\Throwable $e) { }` — empty | Order cancelled, **charge survives** |
| Clinical Procedure `:96` | no `try`/`catch` at all | **Clinical cancellation blocked by a billing fault** |

The three empty blocks carry this comment:

```php
} catch (\Throwable $e) {
    // Failsafe: logging failure without blocking clinical cancellation
}
```

**The comment says logging. The code logs nothing.** A comment that describes behaviour the
code does not have is worse than no comment — it stops the next reader from looking.

**Patient-facing consequence.** Cancel a lab order, let `CancelServiceChargeUseCase` fail for
any reason, and a live `PENDING_PAYMENT` charge survives. The patient is billed at the counter
for a test that was never performed, and **there is no trace anywhere in the system**.

Clinical Procedure has the opposite fault: with no `catch`, a Revenue failure propagates into
the clinical cancellation path, so a billing problem can prevent a clinician from cancelling
an order. Both trade-offs are wrong in opposite directions.

**The correct behaviour already exists in this codebase.** `ConsultationChargeRaiser` catches
`Throwable`, emits `Log::warning` with structured context, and continues — never blocking the
clinical action, never losing the trace. That is the standard; these four regress from it.

### D2 — Domain modeling: 15/20 (−5)

The gate predicate is expressed in two incompatible shapes:

```php
// Laboratory / Radiology / Pharmacy — DENYLIST
$status !== LaboratoryOrderStatus::CANCELLED->value && config(...)

// Clinical Procedure — ALLOWLIST (correct)
in_array($status, [SCHEDULED, IN_PROGRESS, COMPLETED], true)
```

**The allowlist is right.** The denylist gates *every* status except cancellation — including
statuses that were never meant to be gated — and silently acquires any status added to the
enum in future. It fails **open in the wrong direction**: new states become gated by accident.

### D3 — Architectural conformance: 15/20 (−5)

`cancelPendingServiceCharge()` is a private method **copy-pasted into three modules** with
identical bodies differing only in `ChargeSourceKind`. The gate check is likewise inlined into
each `Update*OrderStatusUseCase` and `Apply*OrderLifecycleActionUseCase`.

This is precisely the duplication `PatientFlowStep` was created to eliminate — its own docblock
records collapsing "five copies of the status → step mapping onto one enum." The lesson was
learned and then not applied one module over.

### D4 — Test assurance: 15/25 (−10)

Each module has a `*PrepaidWorkflowTest` covering the **happy gate** (unpaid order cannot
advance). Missing:

- No test on the **cancel-charge path** — the exact code containing the empty catches.
- No test that a cancelled order leaves **no live charge**.
- No configuration-contract test (finding C1).
- Uncommitted gate code in all four modules currently has no accompanying test at all.

### D5 — Observability: 4/15 (−11)

Cross-cutting finding C2. Aggravated here: the empty catches mean these modules are *below*
the system baseline — they do not even produce the log line the rest of the codebase does.

### D6 — Concurrency: 3/5 (−2)

No test covers order-cancel racing charge-authorization. Two operators — a clinician
cancelling and a cashier taking payment — can interleave, and the outcome is unspecified.

---

## Progress

**G1–G4 are done. G5 is not.** This is the tier that moved furthest, because its
deductions were concentrated in one duplicated routine rather than spread thin.

**G1 — failure semantics.** All four modules now log with structured context and continue.
Fixing them surfaced a second defect the audit had missed: `CancelServiceChargeUseCase`
types `$actorUserId` as a **non-nullable** `int`, and Clinical Procedure passed `?int`
straight through — a guaranteed `TypeError` for any console, system or background actor,
with no `catch` to contain it. Both normalised at the one call site that remains.

**G2 — `PrepaidGatePolicy`.** The audit said the gate existed in four copies. It existed in
**eight** — four enforcing transitions in the use cases, four filtering worklist visibility
in the repositories, each retyping the config key by hand. All eight now route through one
policy, and the key is derived once via `ChargeSourceKind::prepaidGateEnabled()`.

The denylist→allowlist conversion was the risky part, so it was checked twice before being
made: no transition map targets an initial status, and each repository's
`applyAuthorizedFilter()` had *already* encoded exactly the allowlist derived independently.
The suite returned byte-identical results across the refactor — 257 passed, 887 assertions —
which is what a behaviour-preserving change should produce.

**G3 — cancel-path and contract tests.** `ChargeCancellationResilienceTest` drives all four
modules from one dataset, because the defect was four copies drifting apart and a shared
test is what stops them drifting again. Verified by regression: the swallow fails the log
assertion, the unprotected propagation fails the clinical-cancellation assertion.

**G4 — telemetry.** Shared with `01-revenue-cashier.md` G2. `charge.cancel_failed` is now
countable, not merely readable.

### Still open

**G5** (cancel-vs-pay concurrency) is untouched, and the rule it would pin is still
unstated: whether a charge that has reached `AUTHORIZED` should be cancelled by a later
order cancellation, or become a refund. Concurrency stays at 3.

---

## Goals to reach 100

### G1 — Fix the four failure behaviours (+9) · ~2h · **do first**

**Goal.** A billing fault never blocks a clinical action, and never disappears.

**Do.** Replace all four with the `ConsultationChargeRaiser` shape — `Log::warning` with
structured context (`order_id`, `charge_id`, `source_kind`, `error`), then continue. Add the
missing `try`/`catch` to Clinical Procedure `:96`.

**Acceptance.** Force `CancelServiceChargeUseCase` to throw; assert (a) the clinical
cancellation still succeeds in all four modules, and (b) a warning with the order id is
emitted in all four. Delete the misleading comments.

### G2 — Extract one `PrepaidGatePolicy` (+10: D2 +5, D3 +5) · ~1d

**Goal.** One implementation of the gate, consulted by all four modules.

**Do.** Create a single policy object in Revenue exposing:

```php
assertAuthorized(ChargeSourceKind $kind, string $orderId, string $targetStatus): void
cancelPendingCharge(ChargeSourceKind $kind, string $orderId, string $reason, ?int $actorId): void
```

Use **Clinical Procedure's allowlist** as the canonical shape — each module declares the
statuses that represent service delivery; the policy decides. Delete the four inline copies
and the three duplicated private methods.

**Acceptance.** `grep -rn "prepaid_required_for" app/Modules/{Laboratory,Radiology,Pharmacy,ClinicalProcedure}`
returns nothing. Adding a fifth order type requires declaring its gated statuses and no gate
logic.

### G3 — Test the cancel path and the contract (+10) · ~1d

**Goal.** No order can be cancelled while leaving a live charge.

**Do.** Per module: cancel an order with a pending charge, assert the charge is `CANCELLED`
and absent from the cashier queue. Add the shared configuration-contract test from
`01-revenue-cashier.md` G1 covering all four order kinds. Add tests for the uncommitted gate
code before committing it.

**Acceptance.** Reverting G1 turns these tests red.

### G4 — Telemetry (+11) · shares work with `01-revenue-cashier.md` G2

**Goal.** Cancelled-order-with-surviving-charge is detectable without a database console.

**Do.** Emit `charge.cancel_failed` and `order.cancelled_with_live_charge` through the same
`RevenueTelemetry` service.

**Acceptance.** A reconciliation query answers "cancelled orders still carrying a live
charge" and alerts when non-zero.

### G5 — Concurrency test (+2) · ~3h

**Goal.** Cancel-vs-pay is specified, not accidental.

**Do.** Decide the rule — a charge that has reached `AUTHORIZED` is **not** cancelled by a
later order cancellation; it becomes a refund. Pin it with an interleaved test modelled on
`Integration/Revenue/ConcurrentPaymentTest.php`.

**Acceptance.** The race has one documented outcome and a test that fails if it changes.

---

## Sequencing

```
G1 (2h, fixes a live billing hole) → G2 (extract policy) → G3 (tests) → G4 (telemetry) → G5
```

**58 → 67** after G1 alone. **58 → 100** after all five. Estimated total: ~3 engineer-days.

G1 is the highest-urgency item in this entire audit: it is two hours of work, it sits in
uncommitted code, and until it lands, patients can be billed for procedures that never
happened with no record that it occurred.
