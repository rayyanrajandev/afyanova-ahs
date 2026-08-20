# Cashier Workspace — remaining work

**State at handoff:** Phases 0–7 are built, tested and on `main` (`300b04a` plus
uncommitted P5–P7 work). 176 PHP tests, 25 frontend tests, all guards green.

The prepaid ledger, the API and the counter UI all work end to end — verified in
a browser: register → charge → pay → receipt → check-in → clinical queue.

What follows is what is *not* built. Each item says what exists already, so you
are extending rather than discovering.

---

## Ground rules

Read these before touching anything.

1. **Money is never a float.** `App\Modules\Revenue\Domain\ValueObjects\Money`
   holds integer minor units. The API takes minor units in and returns decimal
   strings out. On the frontend, `pages/cashier/cashierFormatters.ts` is the
   only place that converts. Never `parseFloat(x) * 100`.
2. **Tests run on PostgreSQL**, not SQLite — `SELECT … FOR UPDATE`, partial
   unique indexes and forked-process concurrency tests depend on it.
   `tests/Integration/` is the suite for anything needing committed data.
3. **New workspace code calls `/api/v1/cashier/*` only.** Never a generic
   endpoint, even where the controller is shared.
4. **Every UI string is an i18n key**, in `en` *and* `sw`, in the same commit.
   `afyanova/no-hardcoded-strings` is an eslint error and
   `npm run guard:i18n-sync` enforces parity.
5. **After changing `config/roles.php`, run `php artisan roles:sync`** or the
   database pivot keeps the old grants and permissions silently fail.
6. Run `php artisan test`, `npm test`, `npm run build`, `npm run guard:reference`
   and `npm run guard:i18n-sync` before calling anything done.

---

## 1. Wire the endpoints that already exist to the UI

Phase 6 shipped 26 routes. Phase 7 wired six of them. **No backend work is
needed for any of this** — controllers, requests, transformers, use cases,
permissions and tests are all in place. This is UI only.

| Build | Endpoint | Notes |
|---|---|---|
| Ad-hoc charge dialog | `POST cashier/charges` | The button exists in `ChargeBasketPanel.vue` behind `canAddCharge: false`, and `Index.vue` passes `@add-charge="() => {}"`. Needs a catalogue search over `chargeable_items`; there is no search endpoint yet, so add one or filter client-side from a small list. |
| Cash movement dialog | `POST cashier/sessions/{id}/movements` | `CashierSessionBar.vue` already emits `move-cash`; `Index.vue` currently swallows it. Reasons come from `CashMovementReason`: `float_top_up`, `banking_drop`, `petty_cash`, `correction`. |
| Cancel a charge | `POST cashier/charges/{id}/cancel` | Reason is mandatory. Only works while unpaid. |
| Waive / emergency override | `POST cashier/charges/{id}/waive`, `.../emergency-override` | Two separate routes on purpose — a waiver is a supervisor's financial call, an override is a clinician's, and they are held by different roles. The override belongs on a **clinical** surface (triage), not the cashier's. |
| Refunds | `GET/POST cashier/refunds`, `POST cashier/refunds/{id}/approve` | Requester ≠ approver, enforced server-side and by a DB constraint. Money is paid out of a named open drawer. |
| Receipt reprint | `POST cashier/receipts/{id}/reprint` | `printCashierReceipt(receipt, { isReprint: true })` already stamps the paper. Reprint count is a fraud signal — surface it. |
| Z-report | `GET cashier/sessions/{id}/summary` | Returns `409 CASHIER_SESSION_NOT_COUNTED` while the drawer is open. That is deliberate. |
| Day summary | `GET cashier/day/summary` | Supervisor-only (`cashier.reports.read`). Gross, reversed, refunded, net, receipts, reprints, per-session rows. |

**Do not weaken the blind count.** `expectedCash` and `variance` are `null` from
the API while a session is open. The close dialog shows a bare input and reveals
the figures only from the close response. Keep it that way.

---

## 2. Missing backend pieces

Small, and each has an obvious home.

- **`RejectRefundUseCase`** — `refunds.status` already has a `REJECTED` case and
  the columns (`rejected_by_user_id`, `rejected_at`, `rejection_reason`). Mirror
  `ApproveRefundUseCase`. Route: `POST cashier/refunds/{id}/reject`.
- **Live sync channel** — `routes/channels.php` has no cashier channel. Add
  `cashier-queue.{facilityId}` reusing `PatientFlowBoardChannelAuthorizer`, the
  same way `reception-queue.{facilityId}` does, and broadcast on
  `ServiceChargeAuthorized`. Today the queue only refreshes after the acting
  cashier's own payment, so a second cashier sees a stale list.
- **Catalogue search endpoint** — the ad-hoc charge dialog needs one. Suggest
  `GET cashier/chargeable-items?q=` behind `cashier.charges.create`.
- **Reception "Send to cashier"** — reception already shows the payment chip and
  the `Awaiting payment` tab. An explicit action on a blocked row would help,
  but it is a nicety: the patient is already visible and already routed.

---

## 3. Extend the gate to the other services

The mechanism is finished and generic. Turning on lab, imaging, procedures or
pharmacy means, per service:

1. Raise a charge at the right moment — see
   `Revenue\Application\Services\ConsultationChargeRaiser` for the shape. It
   never throws into the clinical flow; copy that.
2. Guard the fulfilment action with
   `ServiceAuthorizationReaderInterface::isAuthorized(kind, id)`.
3. Flip the flag in `config/revenue.php` → `prepaid_required_for`.

`ChargeSourceKind` already has the cases. Every clinical catalogue item already
resolves to a priced `chargeable_item` (237 of 237 linked), so no pricing work
is needed.

**Pharmacy is the hard one and should be last.** Quantity is only known at
dispense and substitution changes the item, so its charge must be raised
provisionally at verification and **re-priced at dispense**, with the difference
settled as a top-up or refund at the counter. Nothing in the current design
assumes charge amounts are immutable, but nothing exercises re-pricing either.

---

## 4. Known debt, none of it blocking

- **12 permissions are checked in code and granted by zero roles** —
  `medical.records.update`, `laboratory.orders.verify`, `appointment.check-out`
  and nine others. All pre-date this work. `PermissionUsageAuditor` now reports
  them under `ungrantedChecks`; run `php artisan rbac:audit-permissions`. Each
  needs a decision about which role should hold it. Worth its own pass.
- **644 files fail `pint`.** Pre-existing and tree-wide. Best done as one commit
  now that the deletions have settled — `vendor/bin/pint --parallel`.
  Until then use `php artisan test` directly; `composer test` aborts at the lint
  step before running a single test.
- **Prettier** is likewise unformatted across `resources/ts`. Not enforced by any
  guard; eslint is the one that matters.
- **Consultation prices are placeholders.** `CONSULT-GENERAL-OPD` at 15,000 TZS
  and `CONSULT-SPECIALIST-OPD` at 30,000 TZS, seeded per facility. Ordinary
  price-book entries — supersede them normally. They are *not* a pricing
  decision.
- **Commit `767f6fc` is not green in isolation** — it carries a `phpunit.xml`
  declaring a `tests/Integration` suite added in the next commit. `main` itself
  is correct. Only affects bisecting that range.

---

## 5. Explicitly out of scope

Not "later in this file" — a different project, and the ledger is built so it
does not need rewriting when they arrive.

No NHIF, no Jubilee, no insurer of any kind. No eligibility check, member
verification, card scan, pre-authorization, claim submission, remittance import
or coverage-limit tracking.

The seams are in place and inert: `payer_class` and the patient/payer
responsibility split on every charge; `ChargeAuthorizationPolicyInterface`
resolved by payer class with one implementation; payer tariffs already supported
by `price_book_entries.payer_contract_id`; `payments.method` typed for
`insurance_settlement`; `receipts.fiscal_status` defaulting to `not_required`.

Adding an insurer should be **a policy registration plus a tariff**. If a change
you are making would break that, it is the wrong change.

---

## Where things are

```
app/Modules/Revenue/          the ledger — charges, payments, receipts, drawer
app/Modules/Payer/            payer contracts + patient insurance (registry only)
config/revenue.php            consultation item codes, prepaid flags, variance tolerance
routes/api-workspaces.php     the cashier/* block
resources/ts/pages/cashier/   the workspace
tests/Feature/Revenue/        ledger + API behaviour
tests/Feature/Rbac/           route-ability contract and the 403 matrix
tests/Integration/Revenue/    forked-process concurrency
```

Start with §1 — it is the highest value per hour, needs no backend work, and
every endpoint it touches is already covered by tests.
