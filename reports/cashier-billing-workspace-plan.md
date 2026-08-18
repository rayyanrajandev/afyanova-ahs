# Cashier & Billing Workspace — Build Plan

**Date:** 2026-08-18
**Scope:** the cashier workspace (`/cashier`), the two ledgers behind it, and the
backend changes four settled decisions require.
**Status:** design only — no code written. All four open questions answered.

Build order so far: `reception → nursing → clinician → laboratory → authorization
pass → radiology → pharmacy (done)` → **cashier (now)**.

Continues `reports/authorization-and-next-workspaces-plan.md`, which ends its build
order at `cashier` and documents the same failure class this plan opens with.

Every number below was measured against the working tree on 2026-08-18 — route
counts from `php artisan route:list`, schema from the live `afyanova-ahs-v2`
database, permission grants from `config/roles.php`, integration bindings from
`BillingIntegrationServiceProvider`. Nothing here is recalled.

---

## 0. Decisions taken

| # | Decision |
|---|----------|
| 1 | **Generic insurance contract, insurer-specific adapters.** NHIF is implemented first; Jubilee and others must be addable as an adapter plus configuration, without touching cashier, billing, clearance, patient or payment domains. |
| 2 | **Consultation requires clearance.** Payment or coverage must be settled *before* consultation, so `consultation` is a clearance source kind. |
| 3 | **Fiscalisation must degrade gracefully.** No TRA VFD credentials are provisioned. Payment must never fail or stall on fiscalisation; the VFD integration stays configurable for when credentials land. |
| 4 | **One register session per cashier.** Each cashier opens and closes their own drawer; cash movements and reconciliation tie to that session. No shared session by default. |

---

## 1. What is already built

147 live routes across the Billing and Pos modules. Most of the surface a cashier
needs exists and has never had a UI.

| Area | Routes | Covers | State |
|------|-------:|--------|-------|
| Payer contracts | 17 | Generic payer model, coverage %, copay, pre-auth, price overrides | **built · core** |
| Invoices `billing/{id}` | 16 | CRUD, payments, status, audit logs | built |
| NHIF driver `billing-nhif` | 13 | Member verify, card verify, claim submit, remittances | built · to generalise |
| Cash ledger `cash-patients` | 8 | Account, balance, charge, payment, refund, void | **blocked** (§3) |
| POS sales, registers, sessions | 25 | Drawer float, quick-pay channels, Z-report | partial |
| Gateway `billing-payments` | 6 | Selcom / M-Pesa initiate, payment links | built |
| Refunds `billing-refunds` | 5 | Create, list, pending, approve | built |
| Price list `chargeable-items` | 4 | Items and versioned prices | built |
| Queue, candidates, counts, preview | 4 | Cashier queue, charge capture | built |
| Receipts `billing-receipts` | 3 | TRA fiscal issue, lookup | needs fallback (§6) |
| Write-offs, discounts, mappings | 7 | Write-off approve, discount policies, consultation pricing | partial |
| Day close `daily-closes` | 2 | Per-tender totals, verification | partial |

### A reversal worth recording

`billing-payer-contracts` — 17 routes, the largest group in the domain — was
previously assessed as enterprise machinery to skip. **Decision 1 makes it the
opposite: it is the scheme-agnostic insurance model, and it is already built.**

```
billing_payer_contracts columns:
  payer_type, payer_name, payer_plan_code, payer_plan_name,
  default_coverage_percent, default_copay_type, default_copay_value,
  requires_pre_authorization, claim_submission_deadline_days,
  settlement_cycle_days, effective_from, effective_to, status
```

Not one NHIF-specific column. `patient_insurance_records` already carries
`billing_payer_contract_id`, `provider_code`, `plan_name`, `copay_percent`,
`coverage_limit_amount`, `verification_status` and `verification_source`.

**The data model already satisfies decision 1** — as do the membership records,
the local authorization rules and the claims module (§5.1). The coupling is
confined to the integration layer, and it is shallow: the blocking piece is that
adapters are bound unconditionally, with nothing on the contract naming which
adapter serves it (§5.2).

---

## 2. The core problem: two ledgers, one counter

Money enters through two independent paths. Both are fully built. Neither writes
to the other, and no report adds them up.

```
POS track — pay first                    Billing track — bill after
─────────────────────                    ──────────────────────────
open register session with float         services delivered against coverage
frontdesk-quick/candidates               charge-capture-candidates
frontdesk-quick/sales      ──╳──         invoice raised and issued
item_reference = order.id    no          billing/{id}/payments
close on expected vs counted link        daily-closes totals by tender

pos_sales                                billing_invoices
pos_sale_lines                           billing_invoice_payments
pos_sale_payments                        billing_daily_closes
pos_register_sessions
```

Verifiable three ways:

1. **`CreateBillingDailyCloseUseCase` references no POS model at all.** A facility
   taking cash at a register and closing the day in Billing reports two different
   numbers for the same day.
2. **A POS sale never creates an invoice.** The only billing reference anywhere in
   the Pos module is `billingServiceCode`, used to price a line
   (`FrontdeskQuickCashierSupport.php:428`).
3. **Clinical orders carry no payment state:**

```
laboratory_orders, radiology_orders, pharmacy_orders
  → columns matching %paid% / %bill% / %payment%: none
```

So "has this been paid for?" is answerable only by joining back through
`pos_sale_lines.item_reference` — which is what the one existing verify endpoint
does, for one channel, and **nothing calls it**.

---

## 3. Blocking defect: the cashier cannot do their job

Five abilities that live routes require are granted to **zero roles** in
`config/roles.php`. The permission rows exist; nobody holds them. Only a
super-admin bypass user can reach these routes today.

```
billing.cash-accounts.read      roles granting: 0   → all 8 cash-ledger routes
billing.cash-accounts.manage    roles granting: 0   → charge, payment, refund, void
pos.sessions.manage             roles granting: 0   → opening/closing the drawer
pos.sales.refund                roles granting: 0   → refunding a POS sale
billing.discounts.manage        roles granting: 0   → creating discount policies
```

`FINANCE.CASHIER` holds 28 permissions. It has `billing.payments.record` and
`pos.sales.create`, but not `pos.sessions.manage` — **a cashier can ring up a sale
and cannot open the drawer it belongs to.** It holds no cash-account permission at
all, so the entire pay-first ledger is unreachable by the only role that would use
it.

Decision 4 sharpens this: if every cashier opens their own session, every cashier
needs `pos.sessions.manage`.

### Why CI did not catch it

`RbacPermissionUsageAuditTest` is a real tripwire — and it currently fails on four
unrelated orphans (`inpatient.ward.create`, `inventory.manage`,
`laboratory.orders.manage`, `laboratory.orders.verify`). But it asserts a
permission is **seeded**, not that any role **grants** it. These five are seeded
and ungranted.

This is the same family as the `medical.records.update` bug
(`api-surface-consolidation-plan.md §2.1`) and the `laboratory.orders.update-status`
bug (`authorization-and-next-workspaces-plan.md §1`). Third occurrence — the
auditor should close the gap permanently, not just be patched again.

**Fix:** grant the five in `config/roles.php`, add `billing.insurance.read` and
`billing.payments.read` to `FINANCE.CASHIER`, and extend `PermissionUsageAuditor`
with a second assertion: *a permission checked in code and granted by zero roles
fails the build*.

---

## 4. Ledger model — settled

> **POS is the ledger of the counter. Billing is the ledger of the receivable.**

Cash and mobile-money taken before service are POS sales against a register
session. Coverage, corporate accounts and any credit exposure become billing
invoices. A fully self-pay patient may never generate an invoice at all.

This matches how the code was already built — `pos_register_sessions` has the
drawer fields, `billing_invoices` has the payer contract and claim machinery — and
how the counter actually runs.

The cost is that **reconciliation becomes mandatory**. Two ledgers with one day
close is a design; two ledgers with two day closes is a defect. §8 is that
reconciliation.

---

## 5. Insurance: generic contract, insurer adapters (decision 1)

**NHIF first, Jubilee later, without redesigning cashier, billing, clearance,
patient or payment domains.** The inspection below establishes that most of this
architecture already exists — the work is smaller and more surgical than the
previous revision of this section claimed.

### 5.1 What already exists and is genuinely insurer-agnostic — KEEP

| Asset | Evidence it is generic |
|-------|------------------------|
| `billing_payer_contracts` | `payer_type` is a **category** (`self_pay`, `insurance`, `employer`, `government`, `donor`, `other`) — not an insurer identity. Carries `payer_name`, `payer_plan_code/name`, `default_coverage_percent`, `default_copay_type/value`, `requires_pre_authorization`, `claim_submission_deadline_days`, `settlement_cycle_days`. No NHIF column. |
| `patient_insurance_records` | Generic membership: FK `billing_payer_contract_id`, `member_id`, `policy_number`, `card_number`, `copay_percent`, `coverage_limit_amount`, `verification_status` (`verified`/`unverified`/`failed`/`expired`), `verification_source`, `last_verified_at`. `insurance_type` values are `insurance`/`government`/`employer`/`donor`/`other` — **no insurer names**. |
| `billing_payer_authorization_rules` | **AfyaNova's own coverage engine.** `coverage_decision`, `coverage_percent_override`, `copay_type/value`, `benefit_limit_amount`, `requires_authorization`, `auto_approve`, `authorization_validity_days`, plus service / diagnosis / age / gender predicates. Zero NHIF references. |
| `claims_insurance_cases` (ClaimsInsurance module) | **Zero NHIF references in the entire module.** Generic case with `payer_type`, `payer_name`, `payer_reference`, `patient_insurance_record_id`, `member_id`, `claim_readiness` and full reconciliation fields. |
| `BillingNhifClaimController::submitClaim($caseId)` | Already loads a **generic `ClaimsInsuranceCaseModel`** and hands it to the NHIF adapter. The layering this decision asks for already exists for claims. |
| `DetermineBillingRouteUseCase` | **No insurer branching.** Resolves the payer contract by `billing_payer_contract_id`, falling back to a name lookup. |
| `codes` JSONB map on catalog tables | `billing_service_catalog_items.codes`, `inventory_items.codes`, `platform_clinical_catalog_items.codes` already hold `codes.NHIF`. Adding `codes.JUBILEE` needs **no migration**. |

**Conclusion: the internal domain model already satisfies the decision.** Nothing
in the list above should be renamed or restructured.

### 5.2 What is actually NHIF-coupled — CHANGE

| Coupling | Severity | Change |
|----------|----------|--------|
| Three interfaces named `Nhif*Interface` | Low | The *shapes* are already generic. Introduce `HealthInsuranceGateway` (§5.3) and let the NHIF classes implement it. **Do not rename working production classes for style** — `NhifMemberVerification` is an accurate name for an NHIF adapter. |
| Bound to concrete classes with no config switch | **High** | `BillingIntegrationServiceProvider` binds `NhifVerificationInterface → NhifMemberVerification` unconditionally. `TraFiscalReceiptInterface` already shows the right pattern (`config(...provider)` + `match`). This is the actual blocker to a second insurer. |
| `NhifTariffSyncService` has **no interface at all** | Medium | Injected concretely into `BillingNhifTariffController`. Tariff sync is optional per insurer — keep it outside the gateway (§5.3). |
| No integration reference on the payer contract | **High** | There is no column identifying which adapter serves a contract; only a nullable `metadata` array. See §5.4. |
| `inventory_items.nhif_code`, `inventory_dispensing_claim_links.nhif_code` | Medium | Dedicated insurer-named scalar columns. The `codes` map already supersedes them. Migrate reads to `codes.NHIF`; leave the columns in place until a later cleanup. **Adding Jubilee must not add a `jubilee_code` column.** |
| `EloquentPatientRepository:282` docblock | Trivial | Comment claims `insurance_type: private/nhif/other/none`. The real enum has no insurer names. Stale comment, no code change. |

### 5.3 The integration boundary — smallest practical contract

Derived from what NHIF actually implements, what AfyaNova actually needs, and
what a second insurer may not support.

```php
interface HealthInsuranceGateway
{
    public function code(): string;                                  // 'nhif'
    public function supports(GatewayCapability $capability): bool;   // negotiate, never assume

    public function verifyMembership(MembershipQuery $query): MembershipVerification;
    public function submitClaim(ClaimSubmission $claim): ClaimSubmissionResult;
    public function getClaimStatus(string $claimReference): ClaimStatusResult;
}
```

Four operations, not nine. The reasoning for each exclusion matters more than the
inclusions:

| Conceptual operation | Verdict | Why |
|----------------------|---------|-----|
| `verifyMember` | **In** | NHIF exposes it. `verifyMember`, `checkCardStatus` and `getMemberDetails` collapse into one business capability: *is this membership valid right now, and on what terms*. |
| `submitClaim`, `getClaimStatus` | **In** | Both already exist on `NhifClaimSubmissionInterface`, and already operate on a generic case. |
| `getEligibility`, `getBenefits` | **Out — AfyaNova owns this** | `billing_payer_authorization_rules` already computes coverage %, copay, benefit limits and authorization requirements locally. Adding a gateway call would create a second source of truth for something the facility contractually owns. |
| `requestAuthorization` | **Deferred** | `requires_authorization` and `auto_approve` are local rules today, and NHIF's current integration exposes no authorization endpoint. Add to the interface when a real insurer workflow demands it — not before. |
| Remittance | **Out of the gateway** | `parseFile`/`reconcile`/`processFile` is a file-driven back-office flow, not a request/response call. Not every insurer sends remittance files. Keep `RemittanceInterface` as a separate optional port. |
| Tariff sync | **Out of the gateway** | Same reasoning. Optional, insurer-specific, and does not feed pricing (§5.6). |

`supports()` is how insurer differences are handled without forcing an adapter to
fake a capability. A caller asks before it calls; an unsupported capability
degrades to the manual path (§5.5) rather than throwing.

### 5.4 Selecting the adapter

**A plan is not an integration provider.** `payer_plan_code` must not select the
driver, and `payer_type` cannot — it is a category with six values shared by every
insurer.

Add one nullable column to `billing_payer_contracts`:

```
integration_code   varchar, nullable    -- 'nhif' | 'jubilee' | null (manual only)
```

```text
Payer Contract                  Payer Contract
  payer_name  = NHIF              payer_name  = Jubilee
  payer_type  = insurance         payer_type  = insurance
  plan        = Plan A            plan        = Plan B
  integration = nhif              integration = jubilee
```

Resolution is a factory, not a framework:

```php
final class HealthInsuranceGatewayFactory
{
    public function for(array $payerContract): ?HealthInsuranceGateway;  // null ⇒ manual
}
```

Roughly twenty lines over a config map. **The previously proposed
`CoverageSchemeRegistry` is removed** — it named a concept AfyaNova does not have
("scheme"), and implied lifecycle and registration machinery this does not need.

```php
// config/billing-integrations.php — extends the existing 'nhif' block
'health_insurance' => [
    'gateways' => [
        'nhif' => ['driver' => 'nhif', /* existing nhif config keys move here */],
        // 'jubilee' => ['driver' => 'jubilee', ...],   ← future
    ],
],
```

### 5.5 Manual fallback must stay distinguishable

Manual verification is required when credentials are unprovisioned, the insurer
API is down, or the insurer offers no verification API at all. It must **never**
be indistinguishable from a successful API verification.

`verification_source` already exists on `patient_insurance_records` and currently
defaults to the string `'manual'` in `VerifyPatientInsuranceRecordUseCase:31`, but
is otherwise unconstrained free text. Constrain it to a domain vocabulary:

```
api          — the insurer gateway returned a verification
manual       — a named user verified the card at the counter
unavailable  — the gateway was attempted and failed; cover recorded provisionally
```

Every non-`api` verification records user, timestamp, reason and card/membership
detail. `patient_insurance_audit_events` already exists for the trail.

### 5.6 Data ownership

| AfyaNova owns (source of truth) | Insurer gateway owns |
|---|---|
| Patient, encounter, clinical services | Member verification result |
| Chargeable items and **all local pricing** | Insurer-side claim state |
| Payer contracts, coverage %, copay, benefit limits, authorization rules | Insurer-specific request/response formats |
| Cashier, POS, payments, receipts, reconciliation | Insurer tariffs (as a snapshot) |
| Membership records, verification audit trail | |
| Claim cases and claim readiness | |

**Pricing is local and there is no competing source of truth today.**
`billing_nhif_tariff_imports` is written by `NhifTariffSyncService` and read only
by `BillingNhifTariffController` — it never feeds `chargeable_items` prices.
Treat imported tariffs as a **stored snapshot for comparison and claim
preparation**, not as authoritative pricing. If that ever changes it is a separate
decision, not a side effect of adding an insurer.

### 5.7 A latent pricing bug found during this inspection

`BillingInvoiceLineItemAutoPricingResolver::resolvePriceTypeForPayerContract`
(line 711) reads `$payerContract['contract_type']`:

```
billing_payer_contracts columns named 'contract_type': 0
```

**The column does not exist.** The value is always null, so the `match` always
falls through to `default => 'contract'`, and the entire arm — including its
`'nhif'` alias — is unreachable. Insurance payer contracts never receive
`'insurance'` pricing.

The previous revision of this section described that line as scheme-name matching
in a live pricing path. That was overstated: it is dead code, and the `'nhif'`
alias sat in a *category* list alongside `'insurance'` and the typo
`'insurrance'`, never selecting an integration. The real defect is that the method
reads a non-existent key and should read `payer_type`. Fixing it changes pricing
behaviour for every insurance contract, so it is **its own change with its own
tests**, not a quiet rider on the insurance work.

### 5.8 Cashier and clearance stay insurer-agnostic

The cashier calls one endpoint and never names an insurer:

```jsonc
// POST /api/v1/cashier/coverage/verify
{ "patientId": "01a0…", "memberId": "…", "cardNumber": "…" }

{ "data": {
    "verified": true,
    "payerName": "…", "planName": "…",
    "coveragePercent": 80, "copayType": "percentage", "copayValue": 20,
    "requiresPreAuthorization": false,
    "verificationSource": "api",        // api | manual | unavailable
    "gateway": "nhif",                  // informational only — never a UI branch
    "verifiedAt": "2026-08-18T09:14:00+03:00" } }
```

There is no NHIF screen and no Jubilee screen. The cashier sees member, payer,
plan, eligibility, coverage, copay, authorization — and the verification source,
because a manual verification must look different from an API one.

Clinical clearance (§7) asks *"is this service financially cleared?"*, never *"is
NHIF cleared?"*. `consultation`, `laboratory_order`, `radiology_order`,
`pharmacy_prescription`, `clinical_procedure_order` and `procedure` all use the
one clearance contract, which consults coverage through the gateway only when the
local rules require it.

### 5.9 What adding Jubilee later requires

| Requires | Does **not** require |
|----------|----------------------|
| One `JubileeGateway` class implementing the interface | Any change to cashier UI or endpoints |
| One config entry under `health_insurance.gateways` | Any change to clearance, POS, payments or receipts |
| `integration_code = 'jubilee'` on the payer contract row | Any migration on payer contracts or membership records |
| `codes.JUBILEE` on catalog items that need it | A `jubilee_code` column |
| Its own adapter tests | Changes to `claims_insurance_cases` or the claim flow |
| A `supports()` map for capabilities Jubilee lacks | Forcing Jubilee to fake unsupported operations |

### 5.10 Verdict summary

- **KEEP** — payer contracts, membership records, authorization rules, claims cases, `codes` map, `DetermineBillingRouteUseCase`, the generic claim controller layering, and the existing NHIF class names.
- **CHANGE** — introduce `HealthInsuranceGateway` + `HealthInsuranceGatewayFactory`; add `integration_code` to payer contracts; move NHIF config under `health_insurance.gateways`; constrain `verification_source`; migrate `nhif_code` reads to the `codes` map.
- **REMOVE** — `CoverageSchemeRegistry` (wrong concept, unnecessary machinery); `getEligibility`/`getBenefits` from the interface (AfyaNova owns coverage); the claim that the pricing resolver does live scheme matching.
- **DEFER** — `requestAuthorization` until a real insurer workflow needs it; remittance and tariff sync as separate optional ports; dropping the `nhif_code` columns; the `contract_type` pricing fix as its own change.


## 6. Fiscalisation with graceful fallback (decision 3)

Two findings shape this, and **the second is more urgent than the first**.

### Failure already does not throw

`TotalVfdReceipt::issueReceipt` catches `\Throwable` and returns
`FiscalReceiptResponse(success: false)`. A payment does not currently crash when
the VFD is unreachable.

But `BillingIntegrationService` writes a `billing_tra_receipts` row **only** when
`success === true` (line 127), with `status` hard-coded to `'active'`. So an
unfiscalised payment leaves no record that it needs fiscalising, and there is
nothing to retry from.

### Every payment would stall for 30 seconds

`TotalVfdReceipt` has **no guard on empty credentials** — verified, zero
occurrences of an `apiKey` emptiness check in the file. With `TOTALVFD_API_KEY`
unset, every receipt attempt still issues a live HTTP call to
`https://testapi.totalvfd.co.tz` under `TOTALVFD_TIMEOUT` (default **30 s**)
before returning failure.

Since no credentials are provisioned, **this is the difference between a working
till and an unusable one.** It moves ahead of almost everything else in §9.

### Changes

| Change | Detail |
|--------|--------|
| Add a `null` driver | `TRA_VFD_PROVIDER=null` returns non-fiscal immediately. Default until credentials land. No network call, no timeout. |
| Guard empty credentials | The `totalvfd` driver short-circuits to non-fiscal when TIN, API key or EFD serial is blank — a misconfigured facility behaves like an unprovisioned one, not a slow one. |
| Always write the receipt row | Persist on failure too, using the existing `status` column: `fiscalised` · `pending_fiscalisation` · `non_fiscal`. |
| Facility receipt number | Non-fiscal receipts get a local sequential number so the patient still leaves with a reference, clearly marked as not a fiscal receipt. |
| Retry path | `POST /api/v1/cashier/receipts/{id}/fiscalise` plus a queued retry for `pending_fiscalisation`, so a day's takings can be fiscalised once the device is provisioned. |

### The contract

**Fiscalisation is never inside the payment's transaction boundary.** The sale,
the payment and the order clearance commit; the receipt is issued after and may
fail. `POST /cashier/payments` returns `201` with `receipt.mode: "non_fiscal"` —
the cashier sees the state plainly and the patient is never held at the window for
a tax API.

When credentials do arrive, `verification_link` on `billing_tra_receipts` is the
QR payload. Rendering the receipt QR is a frontend library call, not a compliance
research project.

---

## 7. Payment clearance at the point of service (decision 2)

Decision 2 adds `consultation` to the source kinds, which makes this the gate for
the whole visit rather than for diagnostics only.

### `GET /api/v1/billing/orders/{sourceKind}/{reference}/clearance`

The single source of truth for "may this be served?". Checks POS sale lines,
billing invoice lines **and** active coverage, so it answers correctly for a cash
patient, a scheme patient and a corporate patient alike.

- **sourceKind:** `consultation` · `laboratory_order` · `radiology_order` ·
  `pharmacy_prescription` · `clinical_procedure_order` · `procedure`
- **Permission:** `billing.payments.read` — must be granted to clinical roles, not
  only finance
- **Supersedes:** `pos/frontdesk-quick/verify/{sourceKind}/{orderId}`, kept as a
  deprecated alias

```jsonc
{ "data": {
    "sourceKind": "consultation",
    "reference": "01a0105c-…",                 // encounter id for consultation
    "clearance": "cleared",                    // cleared | blocked | waived | not_required
    "settledBy": "coverage",                   // pos_sale | invoice | coverage | waiver
    "reason": "Covered at 80%, copay 5,000 collected",
    "amountDue": 0, "amountPaid": 5000,
    "receiptReference": "NF-2026-000412" } }
```

### `POST /api/v1/billing/orders/clearance`

Batch form. A lab worklist renders 40 orders and must not issue 40 requests. Takes
an array of `{sourceKind, reference}`, returns clearance objects keyed by
reference. Capped at 200.

### `EnsureOrderIsCleared` middleware

Enforcement, not display. Applied to start-work transitions: **consultation start**
in Clinician, specimen collection in Laboratory, study start in Radiology, dispense
in Pharmacy, and Clinical Procedures. Returns `402 Payment Required` with the
clearance payload so each workspace can show the cashier referral inline.

- **Override:** new ability `billing.payment-hold.override`, written to the order
  audit log with a reason. Emergencies outrank receipts.
- **Config:** facility flag `billing.enforce_pay_before_service`, default **on**
  per decision 2.
- **Consultation:** gates the encounter-start transition, so an unpaid patient
  never reaches a doctor's open note.

---

## 8. The cashier API surface

A new `cashier/` group in `routes/api-workspaces.php`, mirroring the shape the
other five workspaces use. There are prefixes for `clinician/`, `reception/`,
`nursing/`, `pharmacy/`, `radiology/` and `laboratory/` — and no `cashier/`.

Decision 4 lets every session route assume a single open session for the current
user, so none of them take a register id.

| Route | Purpose | Backed by |
|-------|---------|-----------|
| `GET cashier/workspace` | Bootstrap: my open session, queue counts, today's totals | **new aggregate** |
| `GET cashier/queue` | Patients owing money, by payer type and stage | `cashier-queue` |
| `GET cashier/patients/{id}/workspace` | One patient: charges, invoices, cash balance, coverage | `billing/{patientId}/workspace` |
| `GET cashier/patients/{id}/payable-items` | Unpaid items across all source kinds, priced | `frontdesk-quick/candidates` |
| `POST cashier/quote` | Price a basket — catalogue, payer contract, discount applied | **new** |
| `POST cashier/payments` | Take payment. Routes to POS sale or invoice per §4. | **new facade** |
| `POST cashier/coverage/verify` | Scheme-agnostic coverage check (§5) | **new** |
| `GET cashier/session` | My open session, or null | `pos/sessions` |
| `POST cashier/session/open` | Open my drawer with a float | `pos/registers/{id}/sessions` |
| `PATCH cashier/session/close` | Close my drawer, counted cash, discrepancy note | `pos/sessions/{id}/close` |
| `GET cashier/session/report` | Z-report for my shift | `GetPosRegisterSessionReportUseCase` |
| `POST cashier/receipts/{id}/fiscalise` | Retry fiscalisation once the VFD is provisioned (§6) | **new** |
| `GET cashier/day/reconciliation` | Both ledgers for one day, with the variance stated | **new** |
| `POST cashier/day/close` | Close the day across both ledgers atomically | **new** |

### `POST /api/v1/cashier/payments` — the routing facade

The one endpoint the UI calls to take money. It consults
`DetermineBillingRouteUseCase`, writes to the correct ledger, clears the items and
attempts a receipt — so the frontend never learns there are two ledgers.

```jsonc
// request
{
  "patientId": "01a0105c-…",
  "items": [ { "sourceKind": "consultation",     "reference": "01a0…" },
             { "sourceKind": "laboratory_order", "reference": "01a0…" } ],
  "tender": [ { "method": "mpesa", "amount": 25000, "reference": "SFH8K2L9QP" } ],
  "issueReceipt": true
}

// 201
{ "data": {
    "settlement": "pos_sale",
    "saleId": "01a0…", "saleNumber": "POS-2026-001183",
    "sessionId": "01a0…",
    "receipt": { "mode": "non_fiscal",
                 "number": "NF-2026-000412",
                 "verificationLink": null,
                 "status": "pending_fiscalisation" },
    "clearedItems": ["01a0105c-…", "01a0105e-…"] } }
```

- **Permission:** `pos.sales.create` + `billing.payments.record`
- **Precondition:** `409` when the cashier has no open session — decision 4 makes
  this unambiguous
- **Atomicity:** sale, payment and clearance in one transaction. Receipt issued
  outside it (§6).

### `GET /api/v1/cashier/day/reconciliation`

One day's money across both ledgers with the variance **stated**, rather than left
for a human to find. This is what makes the two-ledger model safe.

```jsonc
{ "data": {
    "date": "2026-08-18",
    "pos":      { "sessions": 3, "cash": 412000, "mpesa": 180000,
                  "expectedCash": 412000, "countedCash": 409000,
                  "discrepancy": -3000 },
    "billing":  { "invoicePayments": 6, "cash": 95000, "mpesa": 240000,
                  "card": 150000, "coverageAccrued": 880000 },
    "combined": { "cash": 507000, "mpesa": 420000, "card": 150000,
                  "totalCollected": 1077000 },
    "variance": { "unreconciledPosSales": 0,
                  "itemsServedWithoutClearance": 2,
                  "receiptsPendingFiscalisation": 14,
                  "status": "attention" } } }
```

- **Permission:** `billing.financial-controls.read`
- **Close rule:** `POST cashier/day/close` rejects with `409` and `openSessions[]`
  while any register is still open.

---

## 9. Build order

Revised for decision 1. The insurance boundary lands in two steps — the seam, then
the NHIF adapter behind it — and both sit before reconciliation, because the
cashier workspace consumes the coverage endpoint they produce.

| # | Phase | Why here |
|---|-------|----------|
| 1 | **Unblock the role** | Grant the five ungranted abilities, add `billing.insurance.read` and `billing.payments.read` to `FINANCE.CASHIER`, extend `PermissionUsageAuditor` to fail on zero-role grants. Half a day. Nothing else can be tested until it lands. |
| 2 | **Make fiscalisation safe without credentials** | §6 — `null` driver, empty-credential guard, always-write receipt rows, local receipt number. Small, and it is the difference between a till that works today and one that stalls 30 s per sale. |
| 3 | **Establish the insurance boundary** | §5.3–5.5 — `HealthInsuranceGateway`, `HealthInsuranceGatewayFactory`, `integration_code` on payer contracts, config moved under `health_insurance.gateways`, `verification_source` vocabulary constrained. **No behaviour change**: with one gateway registered the system behaves exactly as it does today. |
| 4 | **Implement the NHIF adapter behind it** | Existing NHIF classes implement the new interface and are resolved through the factory. Keep the class names. Existing `billing-nhif/*` routes and tables stay. This is the step that proves the seam holds with a real insurer. |
| 5 | **Reconciliation before UI** | §8's day endpoints. Far cheaper now than retrofitted after a month of real transactions have accumulated in both ledgers. |
| 6 | **The cashier workspace** | The `cashier/` route group and the Vue workspace: queue, patient panel, coverage check, quote, take payment, receipt print, drawer open and close, Z-report. |
| 7 | **Clearance enforcement** | §7 — endpoint, batch form, then middleware on Clinician, Laboratory, Radiology, Pharmacy and Procedures behind the facility flag. Last, because it changes five other workspaces and must not ship until the cashier can take the money it demands. |

### Deliberately not in this sequence

- **The `contract_type` pricing fix (§5.7)** — a real bug, but fixing it changes
  pricing for every insurance contract. It needs its own change, its own tests and
  its own verification against real invoices. Bundling it into insurance
  refactoring work would hide a pricing behaviour change inside an
  architecture commit.
- **Dropping the `nhif_code` columns** — deferred until the `codes` map is the
  only read path. No urgency, and it is a data migration.
- **Jubilee** — nothing is built for it. §5.9 is the acceptance test for whether
  this architecture worked, checked when a second insurer is actually contracted.

---

## 10. Out of scope

Deliberately excluded. All of it is built and tested; none of it belongs on a
cashier's screen, and none of it should be deleted — dormant code costs nothing.

- `billing/financial-controls` (4 routes) — revenue recognition and GL posting. A
  finance-office screen, not a counter.
- `billing-sms` (4) — notification layer, independent of the workspace.
- `billing-corporate-accounts` and corporate invoice runs (5) — employer schemes
  with batch runs. Real at scale, not day one.
- `billing-payment-plans` (3) — installments. Rare in outpatient practice here.

Note that `billing-payer-contracts` is **not** on this list. Decision 1 moves it
to the centre of the plan (§1, §5).

---

## 11. Note on the codex volume

`documents/codex/02-workspaces/07-cashier-and-billing.md` (Volume 2.7, status
*Drafted*) specifies the same workspace from the UI side — layout, panes, keyboard
shortcuts, Definition of Done. **It has not been modified by this plan.**

Two points in it will need reconciling against the decisions above once this plan
is accepted:

- §10 *Cash drawer management* should reference `pos_register_sessions` and the
  one-session-per-cashier rule (decision 4).
- §7 *Insurance billing* is written around NHIF; decision 1 makes it scheme-
  agnostic.

Those edits are not made here — the codex is not touched without explicit approval.
