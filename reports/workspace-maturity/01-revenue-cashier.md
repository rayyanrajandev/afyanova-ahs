# Revenue / Cashier — 77 / 100

**Verdict:** the strongest tier in the system, and the only one that reads as genuinely
enterprise in its core. The deductions are almost entirely **operational**, not
architectural: the money model is right, and nobody can tell at runtime whether it is
working.

See `00-rubric-and-scores.md` for the rubric and the cross-cutting findings C1–C3.

| Dimension | Weight | At audit | Now |
|---|---:|---:|---:|
| Domain modeling & state integrity | 20 | 18 | 18 |
| Architectural conformance | 20 | 19 | 19 |
| Failure semantics & resilience | 15 | 13 | 13 |
| Test assurance | 25 | 18 | **23** |
| Observability & operability | 15 | 4 | **11** |
| Concurrency & consistency | 5 | 5 | 5 |
| **Total** | **100** | **77** | **89** |

---

## What earns the score

This is credited explicitly because the remediation below must not damage it.

**Domain modeling (18/20).** Money is a value object, never a float. `ServiceChargeStatus`
carries behaviour (`permitsFulfilment()`) rather than being an inert label, so a waiver and
an emergency override open the gate without faking a payment. `PayerClass::isImplemented()`
prevents raising a charge nobody can settle — the system refuses to strand a patient at a
counter. `ServiceAuthorization` is a single answer shape that a clinical surface can both
*act on* and *show a person*.

**Architectural conformance (19/20).** 18 use cases; controllers are 81–169 lines and stay
thin. Full layering including `Infrastructure/Observers` and `Infrastructure/Policies`.
Revenue announces (`ServiceChargeAuthorized`) and Appointment reacts — no reach-across.

**Failure semantics (13/15).** The fail-open decision in `ServiceAuthorization::notCharged()`
is documented *with its rationale and the rejected alternative*. `PromoteAppointmentOnChargeAuthorized`
catches `Throwable` and logs with full context, on the explicit reasoning that money already
taken must never be undone by a queue-advance failure. This is the standard the rest of the
codebase should be held to.

**Concurrency (5/5).** `ConcurrentPaymentTest`, `DocumentNumberConcurrencyTest`,
`LedgerInvariantsTest`, `LedgerReconciliationTest`. Races are tested, not argued about.

---

## Deductions

### D1 — Observability: 4/15 (−11, the single largest deduction in this report)

**Evidence.** No Prometheus, StatsD, Sentry, or APM. `config/logging.php` is Laravel
default. Zero `Notification::`/`Mail::`/`->notify()` calls in `app/Modules/Revenue/`.

Three business-critical paths fail silently:

| Path | Current signal | Consequence |
|---|---|---|
| `ConsultationChargeRaiser` cannot resolve its item | `Log::warning` | Visit uncharged; patient walks past the cashier |
| `PayerClass` not implemented | `Log::info` | Visit uncharged, by design — but invisible |
| `PromoteAppointmentOnChargeAuthorized` throws | `Log::warning` | Patient paid and stays stuck in `AWAITING_PAYMENT` |

Each is *correctly* fail-open. None is **detectable**. An operator cannot answer "did we
bill everyone we treated today?" without a database console.

### D2 — Test assurance: 18/25 (−7)

25 test files, and the mechanism coverage is excellent. But **every test fabricates its own
catalog item**:

```
RevenueTestSupport::pricedItem('CONSULT-TEST',  '15000.00')
RevenueTestSupport::pricedItem('CONSULT-SEQ',   '15000.00')
RevenueTestSupport::pricedItem('CONSULT-'.Str::upper(Str::random(6)), $price)
```

No test asserts that `config('revenue.consultation.default_item_code')` resolves against
the **seeded** catalog. That is exactly why the suite is green while the gate is dead
(finding C1). There is also no test that the promotion listener actually fires end-to-end
from a real payment.

### D3 — Domain modeling: 18/20 (−2)

`ChargeSourceKind::MANUAL` is an unconstrained catch-all — it bypasses the source-workflow
linkage every other kind relies on, and nothing restricts who may raise one.
`ChargeSourceKind` and `PaymentMethod` both carry uncommitted modifications whose
enum-to-config coupling has no test.

### D4 — Architectural conformance: 19/20 (−1)

`AppointmentResponseTransformer::paymentStatus()` (line 86) service-locates
`app(ServiceAuthorizationReaderInterface::class)` and calls `describe()` **once per row**.
`describeMany()` exists precisely to batch this and is used correctly by
`GetReceptionQueueUseCase:270`. Any endpoint transforming a collection of appointments
issues one `service_charges` query per appointment.

---

## Progress

**G1, G2 and G5 are done. G3 and G4 are not.**

**G1 — configuration-to-data contract.** The cause was sharper than this document first
recorded. Migration `2026_08_19_000006` *does* seed the two consultation items, but resolves
which `(tenant, facility)` scopes to seed by querying `chargeable_items` — and on a fresh
database it runs in the same batch that creates that table, before any seeder fills it. The
loop found nothing and seeded nothing, silently. A data migration that depends on data
seeders produce cannot work on a fresh install. `DskChargeableItemsSeeder` now covers that
path, the migration still covers existing databases, and both are idempotent — asserted,
not assumed.

`PrepaidCatalogContractTest` runs the real `DatabaseSeeder` and fails if config and
catalogue part company. Verified by disabling the fix, which reports: *"Prepaid gate
'consultation' is enabled, but the seeded catalogue holds no active, priced 'consultation'
item."*

**G2 — telemetry.** `RevenueTelemetryRecorder` records anomalies on all ten fail-open paths,
deliberately kept out of `revenue_audit_events` — that table requires an `entity_id`, and a
charge that was never raised has no entity to point at. `revenue:reconcile` groups them by
cause and exits non-zero. `RevenueTelemetryEvent::blocksAPatient()` separates "someone is
stuck at a counter now" from "a figure will be wrong later", so alerting can page on one and
report on the other.

**G5 — end-to-end journey.** This document's original claim that the journey was untested
was **wrong**. `Reception/CheckInGateTest` already walked it through the real cashier path,
including the emergency override. The genuine gaps were narrower: nothing tested that
*booking* raises the charge — every test fabricated one by hand against a synthetic code —
and nothing asserted the patient is visible on the cashier queue between arriving and
paying, or gone from it afterwards. Both now covered, using the *configured* item code.

### Found by operating the system, not planned here

- **Paid-today showed 0 TZS.** Rows summed `outstandingAmount()`, which is zero once
  settled. They now carry `amountDue` and `amountPaid`, and the tab picks.
- **Opening a paid patient showed an empty basket.** `includeSettled` had always been
  supported by the endpoint and never sent by the workspace.
- **A settled charge was labelled "Not priced"** — see C5 in the index.
- **Day summary was empty**, for two stacked reasons: the dialog only fetched in
  `onMounted`, which fired while it was closed and never again; and a cashier does not hold
  `cashier.reports.read`, so the button should never have been offered.
- **`CashierDrawerAvailabilityTest`** pins what a refused payment must leave behind —
  nothing in `payments`, `payment_allocations` or `receipts`, the visit untouched, the
  patient still visible at the counter.

### Still open

**G3** (constrain `ChargeSourceKind::MANUAL`) and **G4** (batch the per-row authorization
read in `AppointmentResponseTransformer`) are untouched. Observability is 11 rather than 15
because nothing schedules `revenue:reconcile`: the signal exists and no one is listening.

---

## Goals to reach 100

Ordered by value per unit of effort. Each goal states its acceptance criterion — the
condition under which the points are awarded.

### G1 — Close the configuration-to-data contract (+7) · ~2h · **do first**

**Goal.** A green test suite must imply a working gate in a real environment.

**Do.** Add `tests/Feature/Revenue/PrepaidCatalogContractTest.php` that runs the **real
seeders** (not fixtures) and asserts, for every kind in `config('revenue.prepaid_required_for')`
that is `true`, that the configured item code resolves to an **active** `chargeable_item`
with a **priced** `price_book_entry` in the seeded facility scope.

**Acceptance.** Deleting the consultation row from `DskChargeableItemsSeeder` turns the
suite red. Today it stays green.

**Also.** Add the missing `CONSULT-GENERAL-OPD` item plus the tier codes at
`config/revenue.php:22-23`, with matching price-book entries.

> **Operational warning.** Seeding these items switches the consultation gate **on**.
> From that moment reception check-in routes to `AWAITING_PAYMENT`, and no patient can be
> served unless a cashier has an open drawer session. Land G1 and G2 together.

### G2 — Make fail-open observable (+11) · ~1d

**Goal.** Every silent revenue-affecting path emits a counter an operator can alert on.

**Do.** Introduce one `RevenueTelemetry` service (model it on Platform's existing
`AuditExportRetryResumeTelemetryEventModel` — the pattern is already in this codebase).
Emit on: `charge.not_raised` (with reason: `no_item` / `payer_unimplemented` / `disabled`),
`charge.unpriced`, `promotion.failed`, `charge.cancel_failed`.

**Acceptance.** A daily reconciliation query answers "visits treated vs visits charged"
and a non-zero delta raises an alert. Keep every current fail-open behaviour exactly as
it is — this goal adds a signal, it does not change a decision.

### G3 — Constrain `ChargeSourceKind::MANUAL` (+2) · ~3h

**Goal.** Ad-hoc charges are auditable and authorized.

**Do.** Require an explicit reason and an actor with a named permission; assert both in
`RaiseServiceChargeUseCase`. Add a test that a `MANUAL` charge without a reason is refused.

**Acceptance.** No code path can create a `MANUAL` charge without recording who and why.

### G4 — Batch the authorization read (+1) · ~1h

**Goal.** No N+1 on any list endpoint.

**Do.** Have `AppointmentResponseTransformer` accept a pre-resolved authorization map
(as `GetReceptionQueueUseCase` already builds via `describeMany`) instead of
service-locating per row. Remove the `app()` call from the transformer.

**Acceptance.** A feature test asserting a bounded query count on the appointment-list
endpoint passes with 25+ appointments.

### G5 — End-to-end promotion test (+1) · ~2h

**Goal.** The Reception→Cashier→Reception round trip is pinned.

**Do.** One test: check in an unpaid visit, assert `AWAITING_PAYMENT` and presence in the
cashier queue, take payment, assert `ServiceChargeAuthorized` fired, assert the appointment
is `WAITING_TRIAGE` and has left the cashier queue.

**Acceptance.** Breaking `PromoteAppointmentOnChargeAuthorized` turns this test red.

---

## Sequencing

```
G1 (contract + seed) ──┬── G2 (telemetry) ── G3 ── G4 ── G5
                       │
       must land together — G1 alone arms a gate
       whose failures are still invisible
```

**77 → 88** after G1+G2. **77 → 100** after all five. Estimated total: ~2.5 engineer-days.
