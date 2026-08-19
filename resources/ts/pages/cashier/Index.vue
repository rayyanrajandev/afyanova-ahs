<!--
  Cashier Workspace — the prepaid counter
  ========================================
  Services are paid for before they are provided. This is where that happens:
  a queue of people who owe money on the left, what they owe on the right, and
  the drawer pinned above both because nothing else works without it.

  Built on the same SplitPane shell as Laboratory, Radiology and Pharmacy.
-->
<script setup lang="ts">
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
import OpenDrawerDialog from "./components/OpenDrawerDialog.vue";
import TakePaymentDialog from "./components/TakePaymentDialog.vue";
import { useCashierPayment } from "./composables/useCashierPayment";
import {
  useCashierQueue,
  type CashierCharge,
  type CashierQueueTab,
} from "./composables/useCashierQueue";
import {
  useCashierSession,
  type CashierSession,
  type CashMovementReason,
} from "./composables/useCashierSession";

const { t } = useI18nSafe();
const toast = useToast();

const queue = useCashierQueue();
const drawer = useCashierSession();
const payment = useCashierPayment();

const showOpenDrawer = ref(false);
const showCloseDrawer = ref(false);
const showPayment = ref(false);
const showAdHocCharge = ref(false);
const showMovement = ref(false);
const showDaySummary = ref(false);
const closeResult = ref<{
  session: CashierSession;
  requiresApproval: boolean;
} | null>(null);

const currencyCode = computed(
  () =>
    queue.basketCurrency.value ?? drawer.session.value?.currencyCode ?? "TZS",
);

onMounted(async () => {
  await Promise.all([drawer.fetchCurrent(), queue.fetchQueue()]);
});

async function selectPatient(patientId: string): Promise<void> {
  await queue.selectPatient(patientId);
  // Pre-select everything that can actually be taken, which is what the
  // cashier wants nine times out of ten; unticking is quicker than ticking.
  payment.beginPayment(queue.payableCharges.value);
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
      @open="showOpenDrawer = true"
      @close="showCloseDrawer = true"
      @move-cash="showMovement = true"
      @day-summary="showDaySummary = true"
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
              @search="
                (term) => {
                  queue.searchTerm.value = term;
                  void queue.fetchQueue(true);
                }
              "
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
              :can-take-payment="true"
              :can-add-charge="true"
              @toggle="toggleCharge"
              @take-payment="openPaymentDialog"
              @add-charge="showAdHocCharge = true"
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

    <TakePaymentDialog
      :open="showPayment"
      :due-minor="payment.dueMinor.value"
      :tendered-minor="payment.tenderedMinor.value"
      :change-minor="payment.changeMinor.value"
      :is-short="payment.isShort.value"
      :can-submit="payment.canSubmit.value"
      :is-submitting="payment.isSubmitting.value"
      :currency-code="currencyCode"
      @update:open="showPayment = $event"
      @update:tendered="payment.tenderedMinor.value = $event"
      @confirm="confirmPayment"
    />
  </div>
</template>
