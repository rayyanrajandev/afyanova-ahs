<script setup lang="ts">
import { Receipt, Printer } from "lucide-vue-next";
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

interface TransactionMethod {
  method: string;
  amount: string;
  reference: string | null;
}

interface Transaction {
  id: string;
  paymentNumber: string;
  patientId: string;
  receiptNumber: string | null;
  amount: string;
  receivedAt: string | null;
  methods: TransactionMethod[];
}

interface ShiftSummary {
  session: {
    id: string;
    sessionNumber: string;
    openedAt: string;
    cashierName: string;
  } | null;
  transactions: Transaction[];
  totalsByMethod: Record<string, string>;
  totalGross: string;
  currencyCode: string;
}

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ (e: "update:open", value: boolean): void }>();
const { t } = useI18nSafe();

const summary = ref<ShiftSummary | null>(null);
const isLoading = ref(false);
const error = ref<string | null>(null);

async function load(): Promise<void> {
  isLoading.value = true;
  error.value = null;

  try {
    const response = await fetch("/api/v1/cashier/sessions/current/transactions", {
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    });

    if (!response.ok) {
      error.value = t("cashier.error_load_failed");
      return;
    }

    const data = await response.json();
    summary.value = data?.data ?? null;
  } catch {
    error.value = t("cashier.error_load_failed");
  } finally {
    isLoading.value = false;
  }
}

watch(
  () => props.open,
  (open) => {
    if (open) void load();
  },
);

import { pageRule, printHtmlDocument } from "@/services/print/printDelivery";

function printZReport() {
  if (!summary.value) return;

  const s = summary.value;
  const facilityName = "AFYANOVA HEALTH SYSTEM";
  const sessionNumber = s.session?.sessionNumber ?? "N/A";
  const cashierName = s.session?.cashierName ?? "Unknown";

  const rows = s.transactions.map((txn) => {
    const time = txn.receivedAt ? new Date(txn.receivedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—';
    const methodStr = txn.methods.map(m => m.method.replace('_', ' ')).join(', ');
    return `
      <tr>
        <td>${time}</td>
        <td>${escapeHtml(txn.receiptNumber || '—')}</td>
        <td>${escapeHtml(methodStr)}</td>
        <td class="amt">${escapeHtml(formatMoney(txn.amount, s.currencyCode))}</td>
      </tr>
    `;
  }).join("");

  const tenderRows = Object.entries(s.totalsByMethod).map(([method, amt]) => `
    <tr>
      <td>${escapeHtml(method.replace('_', ' ').toUpperCase())}</td>
      <td class="amt">${escapeHtml(formatMoney(amt, s.currencyCode))}</td>
    </tr>
  `).join("");

  const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Z-Report ${escapeHtml(sessionNumber)}</title>
<style>
  /* Use standard A4 or 80mm based on preference, defaulting to 80mm auto for receipts */
  ${pageRule("80mm auto", "4mm")}
  body {
    font-family: "Courier New", ui-monospace, monospace;
    font-size: 11px;
    line-height: 1.45;
    color: black;
  }
  .center { text-align: center; }
  .facility { font-size: 13px; font-weight: 700; letter-spacing: .5px; }
  .rule { border-top: 1px dashed black; margin: 6px 0; }
  .meta { display: flex; justify-content: space-between; }
  table { width: 100%; border-collapse: collapse; }
  td, th { vertical-align: top; padding: 2px 0; text-align: left; }
  th { border-bottom: 1px solid black; }
  td.amt, th.amt { text-align: right; white-space: nowrap; padding-left: 6px; }
  .totals td { padding: 1px 0; font-weight: 600; }
  .grand td { font-weight: 700; font-size: 12px; padding-top: 4px; }
  .foot { margin-top: 8px; font-size: 10px; text-align: center; }
</style>
</head>
<body>
  <div class="center facility">${escapeHtml(facilityName)}</div>
  <div class="center">Z-REPORT (SHIFT SUMMARY)</div>

  <div class="rule"></div>

  <div class="meta"><span>Session</span><span>${escapeHtml(sessionNumber)}</span></div>
  <div class="meta"><span>Cashier</span><span>${escapeHtml(cashierName)}</span></div>
  <div class="meta"><span>Date</span><span>${new Date().toLocaleDateString()}</span></div>

  <div class="rule"></div>
  <div class="center" style="font-weight:bold; margin-bottom: 4px;">TENDER BREAKDOWN</div>
  <table class="totals">
    ${tenderRows || '<tr><td colspan="2">No transactions</td></tr>'}
    <tr class="grand">
      <td>GROSS TAKINGS</td>
      <td class="amt">${escapeHtml(formatMoney(s.totalGross, s.currencyCode))}</td>
    </tr>
  </table>

  <div class="rule"></div>
  <div class="center" style="font-weight:bold; margin-bottom: 4px;">ITEMIZED LEDGER</div>
  <table>
    <thead>
      <tr>
        <th>Time</th>
        <th>Receipt</th>
        <th>Method</th>
        <th class="amt">Amount</th>
      </tr>
    </thead>
    <tbody>
      ${rows || '<tr><td colspan="4" class="center">No transactions</td></tr>'}
    </tbody>
  </table>

  <div class="rule"></div>

  <div class="foot">
    End of Shift Report<br>
    Printed on ${new Date().toLocaleString()}
  </div>
</body>
</html>`;

  void printHtmlDocument(html, { title: 'Z-Report ' + sessionNumber });
}

function escapeHtml(value: string | null | undefined): string {
  return String(value ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      })[c as keyof typeof escapeHtmlMap] ?? c,
  );
}

const escapeHtmlMap = {
  "&": "&amp;",
  "<": "&lt;",
  ">": "&gt;",
  '"': "&quot;",
  "'": "&#39;",
};
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-3xl max-h-[85vh] flex flex-col">
      <DialogHeader>
        <DialogTitle>My Shift Summary</DialogTitle>
        <DialogDescription>
          {{ summary?.session ? `Session #${summary.session.sessionNumber}` : "No active session" }}
          <span v-if="summary?.session?.cashierName" class="ml-2 pl-2 border-l border-border/60">
            Cashier: {{ summary.session.cashierName }}
          </span>
        </DialogDescription>
      </DialogHeader>

      <div v-if="isLoading" class="py-12 flex justify-center items-center text-sm text-muted-foreground">
        Loading...
      </div>
      
      <p v-else-if="error" class="text-sm text-critical py-4">{{ error }}</p>

      <div v-else-if="summary" class="flex flex-col gap-6 overflow-y-auto flex-1 pr-2">
        
        <!-- Total Takings Banner -->
        <div class="rounded-lg bg-primary/10 border border-primary/20 p-4">
          <p class="text-xs font-semibold text-primary uppercase">Total Gross Takings</p>
          <p class="text-3xl font-bold mt-1 text-primary tabular-nums">
            {{ formatMoney(summary.totalGross, summary.currencyCode) }}
          </p>
        </div>

        <!-- Tender Breakdown -->
        <div>
          <h3 class="text-sm font-semibold mb-3">Tender Breakdown</h3>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div 
              v-for="(amount, method) in summary.totalsByMethod" 
              :key="method"
              class="rounded-lg border border-border/80 bg-muted/40 p-3"
            >
              <dt class="text-xs text-muted-foreground uppercase capitalize">{{ method.replace('_', ' ') }}</dt>
              <dd class="text-base font-semibold tabular-nums mt-1">
                {{ formatMoney(amount, summary.currencyCode) }}
              </dd>
            </div>
            <div v-if="Object.keys(summary.totalsByMethod).length === 0" class="col-span-full text-sm text-muted-foreground">
              No payments taken yet.
            </div>
          </div>
        </div>

        <!-- Itemized Transactions -->
        <div class="flex-1 flex flex-col min-h-0">
          <h3 class="text-sm font-semibold mb-3">Itemized Ledger</h3>
          <div class="rounded-md border border-border overflow-hidden">
            <table class="w-full text-sm">
              <thead class="bg-muted/50">
                <tr class="border-b border-border text-xs text-muted-foreground uppercase">
                  <th class="py-2 pl-4 pr-2 text-left font-medium">Time</th>
                  <th class="py-2 px-2 text-left font-medium">Receipt #</th>
                  <th class="py-2 px-2 text-left font-medium">Method</th>
                  <th class="py-2 pl-2 pr-4 text-right font-medium">Amount</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="txn in summary.transactions" :key="txn.id" class="hover:bg-muted/30">
                  <td class="py-2 pl-4 pr-2 text-muted-foreground tabular-nums whitespace-nowrap">
                    {{ txn.receivedAt ? new Date(txn.receivedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—' }}
                  </td>
                  <td class="py-2 px-2 font-mono text-xs">
                    {{ txn.receiptNumber || '—' }}
                  </td>
                  <td class="py-2 px-2">
                    <div class="flex flex-col gap-0.5">
                      <div v-for="(m, idx) in txn.methods" :key="idx" class="flex items-center gap-1.5 text-xs">
                        <span class="capitalize font-medium">{{ m.method.replace('_', ' ') }}</span>
                        <span v-if="m.reference" class="text-muted-foreground text-[10px]">{{ m.reference }}</span>
                      </div>
                    </div>
                  </td>
                  <td class="py-2 pl-2 pr-4 text-right tabular-nums font-medium">
                    {{ formatMoney(txn.amount, summary.currencyCode) }}
                  </td>
                </tr>
                <tr v-if="summary.transactions.length === 0">
                  <td colspan="4" class="py-8 text-center text-sm text-muted-foreground">
                    <Receipt class="size-8 mx-auto mb-2 opacity-20" />
                    No transactions to show.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

      </div>

      <DialogFooter class="mt-4 pt-4 border-t border-border">
        <div class="flex justify-between w-full">
          <Button variant="outline" @click="printZReport" :disabled="!summary || summary.transactions.length === 0">
            <Printer class="mr-2 size-4" />
            Print Z-Report
          </Button>
          <Button class="cursor-pointer" @click="emit('update:open', false)">
            Done
          </Button>
        </div>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
