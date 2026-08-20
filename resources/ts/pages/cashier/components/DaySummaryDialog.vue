<!--
  DaySummaryDialog — the facility's takings for one day
  =====================================================
  Every figure here is derived from the ledger, never typed. That is the whole
  point of there being one ledger: the retired design had POS and Billing each
  closing their own day, so a facility taking cash at a register and closing in
  billing reported two different numbers for the same day.

  Drawers still awaiting approval are called out at the top, because an
  unapproved variance means the day is not actually closed.
-->
<script setup lang="ts">
import { AlertTriangle } from "lucide-vue-next";
import { onMounted, ref, watch } from "vue";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useI18nSafe } from "@/composables/useI18nSafe";
import { formatMoney } from "../cashierFormatters";

interface DaySessionRow {
  sessionId: string;
  sessionNumber: string;
  cashierUserId: number;
  status: string;
  openingFloat: string;
  cashTaken: string;
  expectedCash: string;
  declaredCash: string | null;
  variance: string | null;
  paymentCount: number;
}

interface DaySummary {
  date: string;
  currencyCode: string;
  grossTakings: string;
  reversed: string;
  refunded: string;
  netTakings: string;
  receiptsIssued: number;
  reprints: number;
  sessions: DaySessionRow[];
  sessionsAwaitingApproval: number;
}

const props = defineProps<{ open: boolean }>();

const emit = defineEmits<{ (e: "update:open", value: boolean): void }>();

const { t } = useI18nSafe();

const summary = ref<DaySummary | null>(null);
const isLoading = ref(false);
const error = ref<string | null>(null);

async function load(): Promise<void> {
  isLoading.value = true;
  error.value = null;

  try {
    const response = await fetch("/api/v1/cashier/day/summary", {
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    });

    if (!response.ok) {
      // A cashier cannot read the day; only a supervisor holds
      // cashier.reports.read. Say so rather than showing an empty report.
      error.value = t("cashier.error_load_failed");

      return;
    }

    summary.value = (await response.json())?.data ?? null;
  } catch {
    error.value = t("cashier.error_load_failed");
  } finally {
    isLoading.value = false;
  }
}

onMounted(() => {
  if (props.open) void load();
});

// The dialog is mounted once by the workspace and toggled by prop, so
// onMounted alone fired while it was still closed and never again — opening it
// showed an empty report because nothing had ever been fetched.
watch(
  () => props.open,
  (open) => {
    if (open) void load();
  },
);

defineExpose({ load });
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>{{ t("cashier.day_summary") }}</DialogTitle>
        <DialogDescription>{{ summary?.date ?? "" }}</DialogDescription>
      </DialogHeader>

      <p v-if="error" class="text-sm text-critical">{{ error }}</p>

      <div v-else-if="summary" class="flex flex-col gap-4">
        <p
          v-if="summary.sessionsAwaitingApproval > 0"
          class="flex items-start gap-2 rounded-md border border-warning/25 bg-warning/5 px-3 py-2 text-xs text-warning"
        >
          <AlertTriangle class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
          {{ t("cashier.sessions_awaiting_approval") }}: {{ summary.sessionsAwaitingApproval }}
        </p>

        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div class="rounded-lg bg-muted/60 px-3 py-2">
            <dt class="text-xs text-muted-foreground">{{ t("cashier.gross_takings") }}</dt>
            <dd class="text-lg font-semibold tabular-nums">
              {{ formatMoney(summary.grossTakings, summary.currencyCode) }}
            </dd>
          </div>
          <div class="rounded-lg bg-muted/60 px-3 py-2">
            <dt class="text-xs text-muted-foreground">{{ t("cashier.refunded") }}</dt>
            <dd class="text-lg font-semibold tabular-nums">
              {{ formatMoney(summary.refunded, summary.currencyCode) }}
            </dd>
          </div>
          <div class="rounded-lg bg-primary/10 px-3 py-2">
            <dt class="text-xs text-muted-foreground">{{ t("cashier.net_takings") }}</dt>
            <dd class="text-lg font-semibold tabular-nums text-primary">
              {{ formatMoney(summary.netTakings, summary.currencyCode) }}
            </dd>
          </div>
          <div class="rounded-lg bg-muted/60 px-3 py-2">
            <dt class="text-xs text-muted-foreground">{{ t("cashier.receipts_issued") }}</dt>
            <dd class="text-lg font-semibold tabular-nums">
              {{ summary.receiptsIssued }}
              <span v-if="summary.reprints > 0" class="text-xs font-normal text-warning">
                · {{ t("cashier.reprints") }} {{ summary.reprints }}
              </span>
            </dd>
          </div>
        </dl>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-border text-xs uppercase text-muted-foreground">
                <th class="py-1.5 text-left font-medium">{{ t("cashier.session_number") }}</th>
                <th class="py-1.5 text-right font-medium">{{ t("cashier.cash_taken") }}</th>
                <th class="py-1.5 text-right font-medium">{{ t("cashier.expected") }}</th>
                <th class="py-1.5 text-right font-medium">{{ t("cashier.counted") }}</th>
                <th class="py-1.5 text-right font-medium">{{ t("cashier.variance") }}</th>
                <th class="py-1.5 text-right font-medium">{{ t("cashier.status") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in summary.sessions"
                :key="row.sessionId"
                class="border-b border-border/60"
              >
                <td class="py-1.5">{{ row.sessionNumber }}</td>
                <td class="py-1.5 text-right tabular-nums">
                  {{ formatMoney(row.cashTaken, summary.currencyCode) }}
                </td>
                <td class="py-1.5 text-right tabular-nums">
                  {{ formatMoney(row.expectedCash, summary.currencyCode) }}
                </td>
                <td class="py-1.5 text-right tabular-nums">
                  {{ row.declaredCash === null ? "—" : formatMoney(row.declaredCash, summary.currencyCode) }}
                </td>
                <td
                  class="py-1.5 text-right tabular-nums"
                  :class="row.variance !== null && row.variance !== '0.00' && 'font-semibold text-critical'"
                >
                  {{ row.variance === null ? "—" : formatMoney(row.variance, summary.currencyCode) }}
                </td>
                <td class="py-1.5 text-right text-xs text-muted-foreground">{{ row.status }}</td>
              </tr>
              <tr v-if="summary.sessions.length === 0">
                <td colspan="6" class="py-4 text-center text-xs text-muted-foreground">
                  {{ t("cashier.no_sessions_today") }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <DialogFooter>
        <Button variant="ghost" class="cursor-pointer" @click="emit('update:open', false)">
          {{ t("cashier.confirm") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
