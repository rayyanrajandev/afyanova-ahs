<!--
  Cashier Workspace — the prepaid counter
  ========================================
  Services are paid for before they are provided. This is where that happens:
  a queue of people who owe money on the left, what they owe on the right, and
  the drawer pinned above both because nothing else works without it.

  Built on the same SplitPane shell as Laboratory, Radiology and Pharmacy.
-->
<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { computed, onMounted, ref } from "vue";
import SplitPane from "@/components/common/SplitPane.vue";
import { useI18nSafe } from "@/composables/useI18nSafe";
import { useToast } from "@/composables/useToast";
import { printCashierReceipt } from "./cashierReceiptPrint";
import AdHocChargeDialog from "./components/AdHocChargeDialog.vue";
import CashierQueuePanel from "./components/CashierQueuePanel.vue";
import CashierSessionBar from "./components/CashierSessionBar.vue";
import CashMovementDialog from "./components/CashMovementDialog.vue";
import ChargeBasketPanel from "./components/ChargeBasketPanel.vue";
import CloseDrawerDialog from "./components/CloseDrawerDialog.vue";
import DaySummaryDialog from "./components/DaySummaryDialog.vue";
import MyShiftSummaryDialog from "./components/MyShiftSummaryDialog.vue";
import OpenDrawerDialog from "./components/OpenDrawerDialog.vue";
import RefundRequestDialog from "./components/RefundRequestDialog.vue";
import RefundReviewDialog from "./components/RefundReviewDialog.vue";
import TakePaymentDialog from "./components/TakePaymentDialog.vue";
import { useCashierBarcodeScanner } from "./composables/useCashierBarcodeScanner";
import { useCashierCustomerDisplaySender } from "./composables/useCashierCustomerDisplay";
import { useCashierLiveSync } from "./composables/useCashierLiveSync";
import { useCashierPayment } from "./composables/useCashierPayment";
import {
  useCashierQueue,
  type CashierCharge,
  type CashierQueueTab,
} from "./composables/useCashierQueue";
import { useCashierRefunds } from "./composables/useCashierRefunds";
import {
  useCashierSession,
  type CashierSession,
  type CashMovementReason,
  type CloseBreakdown,
} from "./composables/useCashierSession";

const { t } = useI18nSafe();
const toast = useToast();

const queue = useCashierQueue();
const drawer = useCashierSession();
const payment = useCashierPayment();
const refunds = useCashierRefunds();
const customerDisplay = useCashierCustomerDisplaySender();

const showOpenDrawer = ref(false);
const showCloseDrawer = ref(false);
const showPayment = ref(false);
const showAdHocCharge = ref(false);
const showMovement = ref(false);
const showDaySummary = ref(false);
const showMyShift = ref(false);
const showRefundRequest = ref(false);
const showRefundReview = ref(false);

/**
 * Actions are rendered from what the signed-in user actually holds, not merely
 * disabled. A cashier who can see an approve button they may never press
 * learns to ignore disabled controls, which is the opposite of what a
 * second-person check is for.
 */
const permissions = computed<string[]>(
  () => (usePage().props.auth as { permissions?: string[] } | undefined)?.permissions ?? [],
);
const canRequestRefund = computed(() => permissions.value.includes("cashier.refunds.request"));
const canApproveRefund = computed(() => permissions.value.includes("cashier.refunds.approve"));
const canReadReports = computed(() => permissions.value.includes("cashier.reports.read"));
const closeResult = ref<{
  session: CashierSession;
  requiresApproval: boolean;
  breakdown: CloseBreakdown | null;
} | null>(null);

const currencyCode = computed(
  () =>
    queue.basketCurrency.value ?? drawer.session.value?.currencyCode ?? "TZS",
);

/**
 * Another till taking a payment must show up here without anyone pressing
 * anything — a refetch, not a payload, so the counter reads the ledger rather
 * than a copy of it that arrived over a wire.
 */
useCashierLiveSync({
  onQueueUpdated: () => {
    void queue.refresh();

    if (canApproveRefund.value) {
      void refunds.fetchPending();
    }
  },
});

/**
 * Barcode & 2D QR gun listener: scanning a patient's wristband, clinic card
 * or routing slip immediately searches and selects them.
 */
useCashierBarcodeScanner({
  onScan: async (scannedText: string) => {
    const term = scannedText.trim();
    if (!term) return;

    // Check if patient is already in current loaded rows
    const matched = queue.rows.value.find(
      (r) =>
        r.patientNumber?.toLowerCase() === term.toLowerCase() ||
        r.patientId === term,
    );

    if (matched) {
      await selectPatient(matched.patientId);
      toast.info(matched.patientName ?? matched.patientNumber ?? term);
    } else {
      queue.search(term);
    }
  },
});

onMounted(async () => {
  await Promise.all([drawer.fetchCurrent(), queue.fetchQueue()]);

  if (canApproveRefund.value) {
    await refunds.fetchPending();
  }
});

async function selectPatient(patientId: string): Promise<void> {
  await queue.selectPatient(patientId);
  // Pre-select everything that can actually be taken, which is what the
  // cashier wants nine times out of ten; unticking is quicker than ticking.
  payment.beginPayment(queue.payableCharges.value);

  customerDisplay.broadcast({
    state: "basket_active",
    patientName: queue.selectedRow.value?.patientName,
    patientNumber: queue.selectedRow.value?.patientNumber,
    currencyCode: currencyCode.value,
    totalDue: queue.basketTotalDue.value,
    charges: queue.charges.value.map((c) => ({
      description: c.description,
      amount: c.amountDue,
      quantity: c.quantity,
    })),
  });
}

function setTab(tab: CashierQueueTab): void {
  queue.setTab(tab);
}

function openPaymentDialog(): void {
  if (!drawer.isOpen.value) {
    // Say so here rather than letting the API refuse: the cashier can act on
    // "open your drawer", not on a rejected request.
    toast.error(t("cashier.drawer_open_required"));
    showOpenDrawer.value = true;

    return;
  }

  payment.beginPayment(
    queue.charges.value.filter((c) =>
      payment.selectedChargeIds.value.includes(c.id),
    ),
  );
  showPayment.value = true;

  customerDisplay.broadcast({
    state: "payment_prompt",
    patientName: queue.selectedRow.value?.patientName,
    patientNumber: queue.selectedRow.value?.patientNumber,
    currencyCode: currencyCode.value,
    totalDue: queue.basketTotalDue.value,
  });
}

function toggleCharge(charge: CashierCharge): void {
  payment.toggleCharge(charge, queue.charges.value);
}

async function confirmPayment(): Promise<void> {
  const patientId = queue.selectedPatientId.value;
  if (patientId === null) return;

  const result = await payment.submit(patientId);
  if (result === null) return;

  showPayment.value = false;

  customerDisplay.broadcast({
    state: "payment_success",
    patientName: queue.selectedRow.value?.patientName,
    patientNumber: queue.selectedRow.value?.patientNumber,
    receiptNumber: result.receipt?.receiptNumber,
    currencyCode: currencyCode.value,
    totalDue: result.amount,
  });

  if (result.receipt) {
    await printCashierReceipt(result.receipt, {
      patientName: queue.selectedRow.value?.patientName,
      patientNumber: queue.selectedRow.value?.patientNumber,
    });
  }

  await queue.refresh();
}

async function addAdHocCharge(payload: {
  chargeableItemId: string;
  quantity: number;
}): Promise<void> {
  const patientId = queue.selectedPatientId.value;
  if (patientId === null) return;

  const response = await fetch("/api/v1/cashier/charges", {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-Requested-With": "XMLHttpRequest",
      "X-CSRF-TOKEN":
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? "",
    },
    credentials: "same-origin",
    body: JSON.stringify({ patientId, ...payload }),
  });

  if (!response.ok) {
    toast.error((await response.json().catch(() => null))?.message ?? t("cashier.error_generic"));

    return;
  }

  showAdHocCharge.value = false;
  await queue.refresh();
  payment.beginPayment(queue.payableCharges.value);
}

async function recordMovement(payload: {
  reason: CashMovementReason;
  amountMinor: number;
  note: string;
}): Promise<void> {
  if (await drawer.recordMovement(payload.reason, payload.amountMinor, payload.note)) {
    showMovement.value = false;
  }
}

async function openRefundRequest(): Promise<void> {
  const patientId = queue.selectedPatientId.value;
  if (patientId === null) return;

  await refunds.fetchPaymentsFor(patientId);
  showRefundRequest.value = true;
}

async function submitRefundRequest(payload: {
  paymentId: string;
  amountMinor: number;
  reason: string;
}): Promise<void> {
  if (await refunds.request(payload.paymentId, payload.amountMinor, payload.reason)) {
    showRefundRequest.value = false;
  }
}

async function reversePayment(payload: {
  paymentId: string;
  reason: string;
}): Promise<void> {
  if (await refunds.reverse(payload.paymentId, payload.reason)) {
    showRefundRequest.value = false;
    await queue.refresh();
  }
}

async function ruleOnRefund(payload: {
  refundId: string;
  decision: "approve" | "reject";
  reason: string;
}): Promise<void> {
  await refunds.rule(payload.refundId, payload.decision, {
    // Approval pays out of the reviewer's own open drawer, so the money shows
    // up in that session's expected cash.
    paidFromSessionId: drawer.session.value?.id,
    reason: payload.reason,
    note: payload.reason,
  });

  await queue.refresh();
}

async function openDrawer(openingFloatMinor: number): Promise<void> {
  if (await drawer.open(openingFloatMinor)) {
    showOpenDrawer.value = false;
  }
}

async function closeDrawer(declaredCashMinor: number): Promise<void> {
  closeResult.value = await drawer.close(declaredCashMinor);
}

function dismissCloseDialog(open: boolean): void {
  showCloseDrawer.value = open;

  if (!open) {
    closeResult.value = null;
    void drawer.fetchCurrent();
  }
}
</script>

<template>
  <!-- AppShell's <main> is a flex row, so the page has to claim its width
       explicitly; without flex-1 it sizes to its content and leaves the
       right of the screen empty. -->
  <div class="flex h-full min-h-0 w-full flex-1 flex-col">
    <CashierSessionBar
      :session="drawer.session.value"
      :is-loading="drawer.isLoading.value"
      :can-review-refunds="canApproveRefund"
      :can-read-reports="canReadReports"
      :pending-refund-count="refunds.pendingCount.value"
      @open="showOpenDrawer = true"
      @close="showCloseDrawer = true"
      @move-cash="showMovement = true"
      @day-summary="showDaySummary = true"
      @my-shift="showMyShift = true"
      @refunds="showRefundReview = true"
    />

    <div class="min-h-0 flex-1">
      <SplitPane persist-key="afyanova:cashier:split" :initial-ratio="0.34">
        <template #start>
          <aside
            class="flex h-full flex-col overflow-hidden rounded-lg border border-border bg-surface"
          >
            <CashierQueuePanel
              :rows="queue.rows.value"
              :counts="queue.counts.value"
              :active-tab="queue.activeTab.value"
              :search-term="queue.searchTerm.value"
              :selected-patient-id="queue.selectedPatientId.value"
              :is-loading="queue.isLoading.value"
              :error="queue.error.value"
              @select="selectPatient"
              @tab="setTab"
              @search="queue.search"
              @retry="queue.fetchQueue()"
            />
          </aside>
        </template>

        <template #end>
          <section
            class="flex h-full flex-col overflow-hidden rounded-lg border border-border bg-surface"
          >
            <ChargeBasketPanel
              :patient="queue.selectedRow.value"
              :charges="queue.charges.value"
              :selected-charge-ids="payment.selectedChargeIds.value"
              :currency-code="currencyCode"
              :unpriced-count="queue.basketUnpricedCount.value"
              :is-loading="queue.isLoadingCharges.value"
              :can-take-payment="drawer.isOpen.value"
              :can-add-charge="true"
              :can-request-refund="canRequestRefund"
              @toggle="toggleCharge"
              @take-payment="openPaymentDialog"
              @add-charge="showAdHocCharge = true"
              @request-refund="openRefundRequest"
            />
          </section>
        </template>
      </SplitPane>
    </div>

    <OpenDrawerDialog
      :open="showOpenDrawer"
      :is-submitting="drawer.isSubmitting.value"
      @update:open="showOpenDrawer = $event"
      @confirm="openDrawer"
    />

    <CloseDrawerDialog
      :open="showCloseDrawer"
      :is-submitting="drawer.isSubmitting.value"
      :currency-code="currencyCode"
      :result="closeResult"
      @update:open="dismissCloseDialog"
      @confirm="closeDrawer"
    />

    <AdHocChargeDialog
      :open="showAdHocCharge"
      :is-submitting="false"
      @update:open="showAdHocCharge = $event"
      @confirm="addAdHocCharge"
    />

    <CashMovementDialog
      :open="showMovement"
      :is-submitting="drawer.isSubmitting.value"
      @update:open="showMovement = $event"
      @confirm="recordMovement"
    />

    <DaySummaryDialog :open="showDaySummary" @update:open="showDaySummary = $event" />
    <MyShiftSummaryDialog :open="showMyShift" @update:open="showMyShift = $event" />

    <RefundRequestDialog
      :open="showRefundRequest"
      :payments="refunds.payments.value"
      :is-loading="refunds.isLoading.value"
      :is-submitting="refunds.isSubmitting.value"
      @update:open="showRefundRequest = $event"
      @confirm="submitRefundRequest"
      @reverse="reversePayment"
    />

    <RefundReviewDialog
      :open="showRefundReview"
      :refunds="refunds.pending.value"
      :is-submitting="refunds.isSubmitting.value"
      :open-session-id="drawer.session.value?.id ?? null"
      @update:open="showRefundReview = $event"
      @rule="ruleOnRefund"
    />

    <TakePaymentDialog
      :open="showPayment"
      :due-minor="payment.dueMinor.value"
      :tendered-minor="payment.tenderedMinor.value"
      :change-minor="payment.changeMinor.value"
      :is-short="payment.isShort.value"
      :can-submit="payment.canSubmit.value"
      :is-submitting="payment.isSubmitting.value"
      :currency-code="currencyCode"
      :payment-method="payment.paymentMethod.value"
      :payment-reference="payment.paymentReference.value"
      :phone-number="payment.phoneNumber.value"
      :tender-lines="payment.tenderLines.value"
      @update:open="showPayment = $event"
      @update:tendered="payment.tenderedMinor.value = $event"
      @update:method="payment.paymentMethod.value = $event"
      @update:reference="payment.paymentReference.value = $event"
      @update:phone="payment.phoneNumber.value = $event"
      @add-tender="payment.tenderLines.value.push($event)"
      @remove-tender="payment.tenderLines.value.splice($event, 1)"
      @confirm="confirmPayment"
    />
  </div>
</template>
