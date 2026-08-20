<!--
  CashierQueuePanel — who is waiting to pay
  ==========================================
  One row per patient rather than per charge: someone with a consultation and
  two lab tests is one person to serve. Built on the shared Queue component so
  the counter sorts, filters and reads like every other worklist in the
  building.
-->
<script setup lang="ts">
import { computed } from "vue";
import Queue, { type QueueItem } from "@/components/common/Queue.vue";
import { Input } from "@/components/ui/input";
import { useI18nSafe } from "@/composables/useI18nSafe";
import { formatMoney } from "../cashierFormatters";
import type {
  CashierQueueRow,
  CashierQueueTab,
} from "../composables/useCashierQueue";

const props = defineProps<{
  rows: CashierQueueRow[];
  counts: Record<CashierQueueTab, number>;
  activeTab: CashierQueueTab;
  searchTerm: string;
  selectedPatientId: string | null;
  isLoading: boolean;
  error: string | null;
}>();

const emit = defineEmits<{
  (e: "select", patientId: string): void;
  (e: "tab", tab: CashierQueueTab): void;
  (e: "search", term: string): void;
  (e: "retry"): void;
}>();

const { t } = useI18nSafe();

const TABS: CashierQueueTab[] = ["awaiting_payment", "paid_today"];

function minutesWaiting(row: CashierQueueRow): number {
  if (!row.oldestChargeAt) return 0;

  return Math.max(
    0,
    Math.round((Date.now() - new Date(row.oldestChargeAt).getTime()) / 60000),
  );
}

const items = computed<QueueItem[]>(() =>
  props.rows.map((row) => {
    const waitMinutes = minutesWaiting(row);

    return {
      id: row.patientId,
      name: row.patientName ?? row.patientNumber ?? "—",
      waitTime:
        waitMinutes < 60
          ? `${waitMinutes} min`
          : `${Math.floor(waitMinutes / 60)}h ${waitMinutes % 60}m`,
      waitMinutes,
      // The amount goes in the status chip, not the subtitle: it is the one
      // thing the cashier is about to ask for, and the subtitle column is
      // narrow enough to truncate it to "TZS 1…".
      category: t("cashier.charge_count", row.chargeCount, { count: row.chargeCount }),
      status: props.activeTab === "paid_today" ? "complete" : "warning",
      // On the paid tab the outstanding figure is always zero; what the
      // cashier wants to see there is what they took.
      statusLabel: formatMoney(
        props.activeTab === "paid_today" ? row.amountPaid : row.amountDue,
        row.currencyCode,
      ),
      // A charge with no price cannot be taken, and the cashier needs to see
      // that before they call the patient over.
      hasWarning: row.unpricedCount > 0,
      priority: waitMinutes >= 30 ? "urgent" : "normal",
    };
  }),
);

const emptyState = computed(() =>
  props.activeTab === "paid_today"
    ? {
        title: t("cashier.queue_paid_empty_title"),
        description: t("cashier.queue_paid_empty_desc"),
      }
    : {
        title: t("cashier.queue_empty_title"),
        description: t("cashier.queue_empty_desc"),
      },
);
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
    <div class="shrink-0 border-b border-border/80 bg-surface px-3 py-2">
      <div
        class="grid grid-cols-2 gap-1 rounded-lg bg-muted/70 p-1 text-xs font-medium"
      >
        <button
          v-for="tab in TABS"
          :key="tab"
          type="button"
          class="flex cursor-pointer items-center justify-center gap-1.5 rounded-md px-2 py-1.5 transition-all"
          :class="
            activeTab === tab
              ? 'bg-card font-semibold text-foreground shadow-2xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="emit('tab', tab)"
        >
          <span class="truncate">{{ t(`cashier.tab_${tab}`) }}</span>
          <span
            class="rounded-full px-1.5 py-0.2 text-xs"
            :class="
              activeTab === tab
                ? 'bg-primary/15 font-bold text-primary'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ counts[tab] ?? 0 }}
          </span>
        </button>
      </div>

      <Input
        :model-value="searchTerm"
        type="search"
        class="mt-2 h-8"
        :placeholder="t('cashier.search_placeholder')"
        :aria-label="t('cashier.search_placeholder')"
        @update:model-value="emit('search', String($event))"
      />
    </div>

    <div class="min-h-0 flex-1">
      <Queue
        :items="items"
        :loading="isLoading"
        :error="error"
        :empty-title="emptyState.title"
        :empty-description="emptyState.description"
        empty-illustration="users"
        default-sort="incoming"
        hide-priority-chips
        @open="emit('select', String($event.id))"
        @retry="emit('retry')"
      />
    </div>
  </div>
</template>
