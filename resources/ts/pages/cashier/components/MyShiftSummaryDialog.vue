<script setup lang="ts">
import {
  Banknote,
  Check,
  Clock,
  Coins,
  Copy,
  CreditCard,
  FileCheck,
  Filter,
  Landmark,
  Layers,
  Printer,
  Receipt,
  RefreshCw,
  Search,
  ShieldCheck,
  Smartphone,
  User,
  Users,
  Wallet,
  X,
} from "lucide-vue-next";
import { computed, ref, watch } from "vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { useI18nSafe } from "@/composables/useI18nSafe";
import { pageRule, printHtmlDocument } from "@/services/print/printDelivery";
import { formatMoney } from "../cashierFormatters";

interface TransactionMethod {
  method: string;
  label?: string;
  amount: string;
  reference: string | null;
}

interface PatientInfo {
  id: string;
  patientNumber: string;
  name: string;
  phone?: string | null;
}

interface Transaction {
  id: string;
  paymentNumber: string;
  patientId: string;
  patient: PatientInfo | null;
  patientName?: string;
  patientNumber?: string | null;
  receiptNumber: string | null;
  amount: string;
  tenderedAmount?: string;
  changeAmount?: string;
  receivedAt: string | null;
  methods: TransactionMethod[];
  isSplit?: boolean;
}

interface MethodBreakdown {
  method: string;
  label: string;
  category: "cash" | "digital";
  amount: string;
  amountMinor: number;
  count: number;
  percentage: number;
}

interface ShiftSummaryMetrics {
  totalGross: string;
  totalCash: string;
  totalDigital: string;
  totalTransactions: number;
  uniquePatientsCount: number;
  receiptsCount: number;
}

interface ShiftSummary {
  session: {
    id: string;
    sessionNumber: string;
    openedAt: string;
    cashierName: string;
    openingFloat?: string;
  } | null;
  summary?: ShiftSummaryMetrics;
  transactions: Transaction[];
  totalsByMethod: Record<string, string>;
  totalsByMethodBreakdown?: MethodBreakdown[];
  totalGross: string;
  currencyCode: string;
}

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ (e: "update:open", value: boolean): void }>();
const { t } = useI18nSafe();

const summary = ref<ShiftSummary | null>(null);
const isLoading = ref(false);
const error = ref<string | null>(null);
const searchQuery = ref("");
const selectedMethodFilter = ref("all");
const copiedReceipt = ref<string | null>(null);

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
    if (open) {
      searchQuery.value = "";
      selectedMethodFilter.value = "all";
      void load();
    }
  },
);

function getMethodConfig(methodKey: string) {
  switch (methodKey) {
    case "cash":
      return {
        label: t("cashier.tender_cash"),
        shortLabel: t("cashier.tender_cash"),
        badgeClass: "bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20",
        icon: Banknote,
        isCash: true,
      };
    case "mobile_money":
      return {
        label: t("cashier.tender_mobile_money"),
        shortLabel: t("cashier.lipa_namba"),
        badgeClass: "bg-sky-500/10 text-sky-700 dark:text-sky-400 border-sky-500/20",
        icon: Smartphone,
        isCash: false,
      };
    case "bank_transfer":
      return {
        label: t("cashier.tender_bank"),
        shortLabel: "SimBanking",
        badgeClass: "bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 border-indigo-500/20",
        icon: Landmark,
        isCash: false,
      };
    case "gepg":
      return {
        label: t("cashier.tender_gepg"),
        shortLabel: "GePG",
        badgeClass: "bg-purple-500/10 text-purple-700 dark:text-purple-400 border-purple-500/20",
        icon: FileCheck,
        isCash: false,
      };
    case "card":
      return {
        label: t("cashier.tender_card"),
        shortLabel: t("cashier.tender_card"),
        badgeClass: "bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20",
        icon: CreditCard,
        isCash: false,
      };
    case "insurance_settlement":
      return {
        label: t("cashier.tender_insurance"),
        shortLabel: t("cashier.tender_insurance"),
        badgeClass: "bg-blue-500/10 text-blue-700 dark:text-blue-400 border-blue-500/20",
        icon: ShieldCheck,
        isCash: false,
      };
    default:
      return {
        label: methodKey.replace(/_/g, " "),
        shortLabel: methodKey.replace(/_/g, " "),
        badgeClass: "bg-muted text-muted-foreground border-border",
        icon: Receipt,
        isCash: false,
      };
  }
}

function getMethodColorClass(methodKey: string): string {
  switch (methodKey) {
    case "cash":
      return "bg-emerald-500";
    case "mobile_money":
      return "bg-sky-500";
    case "bank_transfer":
      return "bg-indigo-500";
    case "gepg":
      return "bg-purple-500";
    case "card":
      return "bg-amber-500";
    case "insurance_settlement":
      return "bg-blue-500";
    default:
      return "bg-slate-400";
  }
}

const methodCounts = computed(() => {
  if (!summary.value) {
    return { all: 0, cash: 0, mobile_money: 0, bank_transfer: 0, gepg: 0, card: 0, split: 0 };
  }
  const txns = summary.value.transactions;
  return {
    all: txns.length,
    cash: txns.filter((t) => t.methods?.some((m) => m.method === "cash")).length,
    mobile_money: txns.filter((t) => t.methods?.some((m) => m.method === "mobile_money")).length,
    bank_transfer: txns.filter((t) => t.methods?.some((m) => m.method === "bank_transfer")).length,
    gepg: txns.filter((t) => t.methods?.some((m) => m.method === "gepg")).length,
    card: txns.filter((t) => t.methods?.some((m) => m.method === "card")).length,
    split: txns.filter((t) => (t.methods?.length ?? 0) > 1).length,
  };
});

const filterPills = computed(() => [
  { id: "all", label: t("cashier.filter_all_tenders"), count: methodCounts.value.all },
  { id: "cash", label: t("cashier.tender_cash"), count: methodCounts.value.cash },
  { id: "mobile_money", label: t("cashier.lipa_namba"), count: methodCounts.value.mobile_money },
  { id: "bank_transfer", label: "SimBanking", count: methodCounts.value.bank_transfer },
  { id: "gepg", label: "GePG", count: methodCounts.value.gepg },
  { id: "card", label: "Card", count: methodCounts.value.card },
  { id: "split", label: t("cashier.filter_split_only"), count: methodCounts.value.split },
]);

const breakdownList = computed(() => {
  if (!summary.value) return [];
  if (summary.value.totalsByMethodBreakdown && summary.value.totalsByMethodBreakdown.length > 0) {
    return summary.value.totalsByMethodBreakdown;
  }
  return Object.entries(summary.value.totalsByMethod).map(([method, amount]) => ({
    method,
    label: getMethodConfig(method).label,
    category: method === "cash" ? ("cash" as const) : ("digital" as const),
    amount,
    amountMinor: 0,
    count: summary.value?.transactions.filter((t) => t.methods.some((m) => m.method === method)).length ?? 0,
    percentage: 0,
  }));
});

const sessionDuration = computed(() => {
  if (!summary.value?.session?.openedAt) return null;
  const opened = new Date(summary.value.session.openedAt).getTime();
  const now = Date.now();
  const diffMinutes = Math.max(0, Math.floor((now - opened) / 60000));
  const hours = Math.floor(diffMinutes / 60);
  const minutes = diffMinutes % 60;
  if (hours === 0) return `${minutes}m`;
  return `${hours}h ${minutes}m`;
});

const filteredTransactions = computed(() => {
  if (!summary.value) return [];
  let txns = summary.value.transactions;

  // Method filter
  if (selectedMethodFilter.value !== "all") {
    if (selectedMethodFilter.value === "split") {
      txns = txns.filter((t) => (t.methods?.length ?? 0) > 1);
    } else {
      txns = txns.filter((t) =>
        t.methods?.some((m) => m.method === selectedMethodFilter.value),
      );
    }
  }

  // Search filter
  const query = searchQuery.value.trim().toLowerCase();
  if (query) {
    txns = txns.filter((t) => {
      const patientName = (t.patient?.name || t.patientName || "").toLowerCase();
      const patientNo = (t.patient?.patientNumber || t.patientNumber || "").toLowerCase();
      const receiptNo = (t.receiptNumber || "").toLowerCase();
      const paymentNo = (t.paymentNumber || "").toLowerCase();
      const refs = t.methods?.map((m) => (m.reference || "").toLowerCase()).join(" ") || "";

      return (
        patientName.includes(query) ||
        patientNo.includes(query) ||
        receiptNo.includes(query) ||
        paymentNo.includes(query) ||
        refs.includes(query)
      );
    });
  }

  return txns;
});

const uniquePatientsInFiltered = computed(() => {
  const ids = new Set(filteredTransactions.value.map((t) => t.patientId || t.patient?.id).filter(Boolean));
  return ids.size;
});

function copyReceipt(receiptNum: string | null) {
  if (!receiptNum) return;
  void navigator.clipboard.writeText(receiptNum);
  copiedReceipt.value = receiptNum;
  setTimeout(() => {
    if (copiedReceipt.value === receiptNum) {
      copiedReceipt.value = null;
    }
  }, 2000);
}

function printZReport() {
  if (!summary.value) return;

  const s = summary.value;
  const facilityName = "AFYANOVA HEALTH SYSTEM";
  const sessionNumber = s.session?.sessionNumber ?? "N/A";
  const cashierName = s.session?.cashierName ?? t("cashier.unknown_patient");
  const openedAt = s.session?.openedAt ? new Date(s.session.openedAt).toLocaleString() : "N/A";
  const printedAt = new Date().toLocaleString();

  const totalGross = s.summary?.totalGross ?? s.totalGross;
  const totalCash = s.summary?.totalCash ?? s.totalsByMethod["cash"] ?? "0.00";
  const totalDigital = s.summary?.totalDigital ?? "0.00";
  const totalPatients = s.summary?.uniquePatientsCount ?? new Set(s.transactions.map((t) => t.patientId)).size;
  const totalTxns = s.summary?.totalTransactions ?? s.transactions.length;

  const breakdownRows = (s.totalsByMethodBreakdown && s.totalsByMethodBreakdown.length > 0
    ? s.totalsByMethodBreakdown
    : Object.entries(s.totalsByMethod).map(([method, amount]) => ({
        method,
        label: getMethodConfig(method).label,
        category: method === "cash" ? ("cash" as const) : ("digital" as const),
        amount,
        amountMinor: 0,
        count: s.transactions.filter((t) => t.methods.some((m) => m.method === method)).length,
        percentage: 0,
      }))
  )
    .map((b) => `
      <tr>
        <td><strong>${escapeHtml(b.label)}</strong> <span class="tag">${escapeHtml(b.category.toUpperCase())}</span></td>
        <td class="center">${b.count}</td>
        <td class="amt"><strong>${escapeHtml(formatMoney(b.amount, s.currencyCode))}</strong></td>
      </tr>
    `)
    .join("");

  const itemizedRows = s.transactions
    .map((txn) => {
      const time = txn.receivedAt
        ? new Date(txn.receivedAt).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })
        : "—";
      const patientDisplay = txn.patient?.name || txn.patientName || t("cashier.unknown_patient");
      const mrnDisplay = txn.patient?.patientNumber || txn.patientNumber || t("cashier.no_mrn");
      const methodStr = txn.methods
        .map((m) => {
          const cfg = getMethodConfig(m.method);
          const ref = m.reference ? ` (${m.reference})` : "";
          return `${cfg.shortLabel}: ${formatMoney(m.amount, s.currencyCode)}${ref}`;
        })
        .join("<br>");

      return `
        <tr>
          <td>${time}</td>
          <td class="mono">${escapeHtml(txn.receiptNumber || txn.paymentNumber || "—")}</td>
          <td>
            <strong>${escapeHtml(patientDisplay)}</strong><br>
            <span class="mrn">${t("cashier.mrn")}: ${escapeHtml(mrnDisplay)}</span>
          </td>
          <td class="methods">${methodStr}</td>
          <td class="amt font-bold">${escapeHtml(formatMoney(txn.amount, s.currencyCode))}</td>
        </tr>
      `;
    })
    .join("");

  const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Z-Report ${escapeHtml(sessionNumber)}</title>
<style>
  ${pageRule("80mm auto", "4mm")}
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Courier New", monospace;
    font-size: 11px;
    line-height: 1.45;
    color: #000;
  }
  .center { text-align: center; }
  .facility { font-size: 14px; font-weight: 800; letter-spacing: 0.5px; margin-bottom: 2px; }
  .sub-title { font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px; }
  .rule { border-top: 1px dashed #000; margin: 6px 0; }
  .double-rule { border-top: 2px solid #000; margin: 8px 0; }
  .meta-row { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 2px; }
  .meta-label { color: #333; }
  .meta-val { font-weight: 600; text-align: right; }
  table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  th { border-bottom: 1px solid #000; font-size: 10px; text-transform: uppercase; padding: 3px 0; }
  td { vertical-align: top; padding: 3px 0; border-bottom: 1px dotted #ccc; }
  td.amt, th.amt { text-align: right; white-space: nowrap; padding-left: 4px; }
  .tag { font-size: 8px; background: #eee; padding: 1px 3px; border-radius: 2px; text-transform: uppercase; margin-left: 2px; }
  .mrn { font-size: 9px; color: #444; font-family: monospace; }
  .mono { font-family: monospace; font-size: 10px; }
  .methods { font-size: 9.5px; }
  .totals-box { background: #f8f8f8; padding: 6px; border: 1px solid #ddd; margin: 6px 0; }
  .grand-row { display: flex; justify-content: space-between; font-size: 13px; font-weight: 800; margin-top: 4px; }
  .sign-box { margin-top: 14px; padding-top: 8px; border-top: 1px dashed #000; font-size: 10px; }
  .sign-line { margin-top: 16px; display: flex; justify-content: space-between; }
  .foot { margin-top: 10px; font-size: 9px; text-align: center; color: #555; }
</style>
</head>
<body>
  <div class="center facility">${escapeHtml(facilityName)}</div>
  <div class="center sub-title">Z-REPORT (${escapeHtml(t("cashier.my_shift_summary").toUpperCase())})</div>

  <div class="rule"></div>

  <div class="meta-row"><span class="meta-label">${escapeHtml(t("cashier.drawer"))}</span><span class="meta-val">#${escapeHtml(sessionNumber)}</span></div>
  <div class="meta-row"><span class="meta-label">${escapeHtml(t("cashier.cashier_label"))}</span><span class="meta-val">${escapeHtml(cashierName)}</span></div>
  <div class="meta-row"><span class="meta-label">${escapeHtml(t("cashier.started_at", { time: "" }).replace(/\{time\}|\s*$/, ""))}</span><span class="meta-val">${escapeHtml(openedAt)}</span></div>
  <div class="meta-row"><span class="meta-label">Time</span><span class="meta-val">${escapeHtml(printedAt)}</span></div>

  <div class="totals-box">
    <div class="meta-row"><span>${escapeHtml(t("cashier.patients_served"))}</span><span class="meta-val">${totalPatients}</span></div>
    <div class="meta-row"><span>${escapeHtml(t("cashier.total_transactions", { count: totalTxns }))}</span><span class="meta-val">${totalTxns}</span></div>
    <div class="meta-row"><span>${escapeHtml(t("cashier.physical_cash"))}</span><span class="meta-val">${escapeHtml(formatMoney(totalCash, s.currencyCode))}</span></div>
    <div class="meta-row"><span>${escapeHtml(t("cashier.digital_and_bank"))}</span><span class="meta-val">${escapeHtml(formatMoney(totalDigital, s.currencyCode))}</span></div>
    <div class="grand-row"><span>${escapeHtml(t("cashier.gross_takings").toUpperCase())}</span><span>${escapeHtml(formatMoney(totalGross, s.currencyCode))}</span></div>
  </div>

  <div class="double-rule"></div>
  <div class="center" style="font-weight:bold; font-size: 11px; margin-bottom: 2px;">${escapeHtml(t("cashier.tender_breakdown_title").toUpperCase())}</div>
  <table>
    <thead>
      <tr>
        <th style="text-align: left;">${escapeHtml(t("cashier.tender_method"))}</th>
        <th class="center">Count</th>
        <th class="amt">${escapeHtml(t("cashier.subtotal"))}</th>
      </tr>
    </thead>
    <tbody>
      ${breakdownRows || `<tr><td colspan="3" class="center">${escapeHtml(t("cashier.no_transactions_found"))}</td></tr>`}
    </tbody>
  </table>

  <div class="double-rule"></div>
  <div class="center" style="font-weight:bold; font-size: 11px; margin-bottom: 2px;">${escapeHtml(t("cashier.itemized_ledger_title").toUpperCase())}</div>
  <table>
    <thead>
      <tr>
        <th style="text-align: left;">${escapeHtml(t("cashier.time"))}</th>
        <th style="text-align: left;">${escapeHtml(t("cashier.receipt_no"))}</th>
        <th style="text-align: left;">${escapeHtml(t("cashier.patient_and_mrn"))}</th>
        <th style="text-align: left;">${escapeHtml(t("cashier.payment_method_and_ref"))}</th>
        <th class="amt">${escapeHtml(t("cashier.amount_due"))}</th>
      </tr>
    </thead>
    <tbody>
      ${itemizedRows || `<tr><td colspan="5" class="center">${escapeHtml(t("cashier.no_transactions_found"))}</td></tr>`}
    </tbody>
  </table>

  <div class="sign-box">
    <div style="font-weight: bold; margin-bottom: 4px;">AUDIT SIGN-OFF & CASH HANDOVER</div>
    <div class="sign-line">
      <span>Cashier Signature: __________________</span>
      <span>Date: ____________</span>
    </div>
    <div class="sign-line">
      <span>Supervisor Sign-off: ________________</span>
      <span>Date: ____________</span>
    </div>
    <div style="margin-top: 12px; font-size: 9.5px;">
      Physical Cash Count Declared: _____________________________
    </div>
  </div>

  <div class="foot">
    End of Shift Audit · Generated by AfyaNova Health System
  </div>
</body>
</html>`;

  void printHtmlDocument(html, { title: `Z-Report-${sessionNumber}` });
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
    <DialogContent class="sm:max-w-5xl max-h-[92vh] flex flex-col p-0 gap-0 overflow-hidden border-border/80 shadow-2xl" :show-close-button="false">
      <!-- 1. Enterprise Hospital Header -->
      <DialogHeader class="px-5 py-3.5 border-b border-border/80 bg-surface/95 backdrop-blur-xs">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
          <div class="flex flex-col gap-1">
            <div class="flex items-center gap-2.5 flex-wrap">
              <DialogTitle class="text-base font-bold tracking-tight text-foreground flex items-center gap-2">
                <Wallet class="size-4 text-primary" />
                {{ t("cashier.my_shift_summary") }}
              </DialogTitle>
              
              <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20">
                <span class="size-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                {{ t("cashier.live_shift") }}
              </span>

              <span v-if="summary?.session" class="inline-flex items-center font-mono text-[11px] font-medium text-muted-foreground bg-muted/60 px-2 py-0.5 rounded border border-border/60">
                {{ t("cashier.drawer") }} #{{ summary.session.sessionNumber }}
              </span>
            </div>

            <!-- Session Metadata Sub-line -->
            <DialogDescription class="flex items-center gap-2 text-xs text-muted-foreground flex-wrap">
              <span v-if="summary?.session?.cashierName" class="flex items-center gap-1">
                <User class="size-3 text-muted-foreground" />
                {{ t("cashier.cashier_label") }}: <strong class="text-foreground font-medium">{{ summary.session.cashierName }}</strong>
              </span>

              <span v-if="summary?.session?.openedAt" class="pl-2 border-l border-border/80 flex items-center gap-1">
                <Clock class="size-3 text-muted-foreground" />
                {{ t("cashier.started_at", { time: new Date(summary.session.openedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }) }}
                <span v-if="sessionDuration" class="text-muted-foreground/80 font-normal">({{ sessionDuration }})</span>
              </span>

              <span v-if="summary?.session?.openingFloat" class="pl-2 border-l border-border/80 flex items-center gap-1">
                <Coins class="size-3 text-muted-foreground" />
                {{ t("cashier.opening_float") }}: <strong class="text-foreground font-mono font-medium">{{ formatMoney(summary.session.openingFloat, summary.currencyCode) }}</strong>
              </span>
            </DialogDescription>
          </div>

          <!-- Quick Action Buttons -->
          <div class="flex items-center gap-1.5 self-end sm:self-auto shrink-0">
            <Button
              variant="outline"
              size="sm"
              class="h-7.5 px-2.5 gap-1.5 cursor-pointer text-xs font-medium"
              :disabled="isLoading"
              @click="load"
            >
              <RefreshCw class="size-3" :class="{ 'animate-spin': isLoading }" />
              {{ t("cashier.refresh") }}
            </Button>

            <Button
              variant="outline"
              size="sm"
              class="h-7.5 px-2.5 gap-1.5 cursor-pointer text-xs font-medium"
              :disabled="!summary || summary.transactions.length === 0"
              @click="printZReport"
            >
              <Printer class="size-3" />
              {{ t("cashier.print_z_report") }}
            </Button>
          </div>
        </div>
      </DialogHeader>

      <!-- 2. Loading / Error / Main Body -->
      <div v-if="isLoading && !summary" class="py-24 flex flex-col justify-center items-center gap-3 text-muted-foreground">
        <RefreshCw class="size-6 animate-spin text-primary" />
        <span class="text-sm font-medium">{{ t("cashier.loading_shift_ledger") }}</span>
      </div>

      <div v-else-if="error" class="p-6 text-center">
        <p class="text-sm font-medium text-critical bg-critical/10 p-4 rounded-lg border border-critical/20">
          {{ error }}
        </p>
      </div>

      <div v-else-if="summary" class="flex-1 overflow-y-auto p-4 space-y-4 bg-muted/10">
        
        <!-- 3. Compact Executive Financial Ribbon (Glanceable Enterprise Stat Bar) -->
        <div class="rounded-lg border border-border/80 bg-surface shadow-2xs overflow-hidden">
          <div class="grid grid-cols-2 lg:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-border/60">
            
            <!-- Cell 1: Gross Takings -->
            <div class="p-3 flex items-center gap-3">
              <div class="size-8.5 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0 border border-primary/20">
                <Wallet class="size-4.5" />
              </div>
              <div class="min-w-0">
                <p class="text-[10px] font-bold text-primary uppercase tracking-wider">{{ t("cashier.gross_takings") }}</p>
                <p class="text-lg font-bold text-foreground tabular-nums tracking-tight font-mono">
                  {{ formatMoney(summary.summary?.totalGross ?? summary.totalGross, summary.currencyCode) }}
                </p>
                <p class="text-[10px] text-muted-foreground truncate">
                  {{ t("cashier.total_transactions", { count: summary.summary?.totalTransactions ?? summary.transactions.length }) }}
                </p>
              </div>
            </div>

            <!-- Cell 2: Physical Cash in Drawer -->
            <div class="p-3 flex items-center gap-3">
              <div class="size-8.5 rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 flex items-center justify-center shrink-0 border border-emerald-500/20">
                <Banknote class="size-4.5" />
              </div>
              <div class="min-w-0">
                <p class="text-[10px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">{{ t("cashier.physical_cash") }}</p>
                <p class="text-lg font-bold text-emerald-950 dark:text-emerald-100 tabular-nums tracking-tight font-mono">
                  {{ formatMoney(summary.summary?.totalCash ?? summary.totalsByMethod['cash'] ?? '0.00', summary.currencyCode) }}
                </p>
                <p class="text-[10px] text-muted-foreground truncate">
                  {{ t("cashier.cash_in_drawer") }}
                </p>
              </div>
            </div>

            <!-- Cell 3: Digital & Electronic -->
            <div class="p-3 flex items-center gap-3">
              <div class="size-8.5 rounded-lg bg-sky-500/10 text-sky-700 dark:text-sky-400 flex items-center justify-center shrink-0 border border-sky-500/20">
                <Smartphone class="size-4.5" />
              </div>
              <div class="min-w-0">
                <p class="text-[10px] font-bold text-sky-700 dark:text-sky-400 uppercase tracking-wider">{{ t("cashier.digital_and_bank") }}</p>
                <p class="text-lg font-bold text-sky-950 dark:text-sky-100 tabular-nums tracking-tight font-mono">
                  {{ formatMoney(summary.summary?.totalDigital ?? '0.00', summary.currencyCode) }}
                </p>
                <p class="text-[10px] text-muted-foreground truncate">
                  {{ t("cashier.digital_hint") }}
                </p>
              </div>
            </div>

            <!-- Cell 4: Patients Served -->
            <div class="p-3 flex items-center gap-3">
              <div class="size-8.5 rounded-lg bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 flex items-center justify-center shrink-0 border border-indigo-500/20">
                <Users class="size-4.5" />
              </div>
              <div class="min-w-0">
                <p class="text-[10px] font-bold text-indigo-700 dark:text-indigo-400 uppercase tracking-wider">{{ t("cashier.patients_served") }}</p>
                <p class="text-lg font-bold text-indigo-950 dark:text-indigo-100 tabular-nums tracking-tight font-mono">
                  {{ summary.summary?.uniquePatientsCount ?? 0 }}
                </p>
                <p class="text-[10px] text-muted-foreground truncate">
                  {{ summary.summary?.receiptsCount ?? 0 }} {{ t("cashier.receipts_issued").toLowerCase() }}
                </p>
              </div>
            </div>

          </div>

          <!-- Proportional Tender Mix Multi-Segment Bar -->
          <div v-if="breakdownList.length > 0" class="border-t border-border/60 bg-muted/20 px-3 py-2">
            <div class="flex items-center justify-between text-[10px] font-medium text-muted-foreground mb-1.5">
              <span class="flex items-center gap-1 uppercase tracking-wider font-semibold">
                <Layers class="size-3 text-primary" />
                {{ t("cashier.tender_mix") }}
              </span>
              <span>{{ t("cashier.methods_used", { count: breakdownList.length }) }}</span>
            </div>

            <!-- Segmented Bar -->
            <div class="h-1.5 w-full bg-muted rounded-full overflow-hidden flex gap-0.5">
              <div
                v-for="item in breakdownList"
                :key="item.method"
                :class="getMethodColorClass(item.method)"
                :style="{ width: `${Math.max(item.percentage, 2)}%` }"
                class="h-full transition-all duration-300"
                :title="`${item.label}: ${item.percentage}% (${formatMoney(item.amount, summary.currencyCode)})`"
              ></div>
            </div>

            <!-- Mini Legend -->
            <div class="mt-1.5 flex items-center gap-3 flex-wrap text-[10px]">
              <div
                v-for="item in breakdownList"
                :key="item.method"
                class="flex items-center gap-1 text-muted-foreground"
              >
                <span class="size-2 rounded-full" :class="getMethodColorClass(item.method)"></span>
                <span class="font-medium text-foreground">{{ getMethodConfig(item.method).shortLabel }}:</span>
                <span class="font-mono">{{ item.percentage }}%</span>
              </div>
            </div>
          </div>
        </div>

        <!-- 4. Structured Dual-Section / Grid with Equal Height Panels -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-stretch">
          
          <!-- Left Sub-Panel: Tender Reconciliation Table (4 cols on lg) -->
          <div class="lg:col-span-4 rounded-lg border border-border/80 bg-surface shadow-2xs overflow-hidden flex flex-col h-full">
            <div class="px-3.5 py-2.5 border-b border-border/60 bg-muted/30 flex items-center justify-between shrink-0">
              <h3 class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                <Layers class="size-3.5 text-primary" />
                {{ t("cashier.tender_breakdown_title") }}
              </h3>
              <span class="text-[10px] text-muted-foreground font-mono">
                {{ breakdownList.length }} {{ t("cashier.method").toLowerCase() }}
              </span>
            </div>

            <div class="overflow-x-auto overflow-y-auto flex-1 flex flex-col justify-between max-h-[380px]">
              <table class="w-full text-xs text-left border-collapse">
                <thead class="bg-muted/40 border-b border-border/60 text-[10px] text-muted-foreground uppercase font-semibold sticky top-0 z-10">
                  <tr>
                    <th class="py-2 pl-3 pr-2">{{ t("cashier.method") }}</th>
                    <th class="py-2 px-1.5 text-center">{{ t("cashier.count") }}</th>
                    <th class="py-2 pl-1.5 pr-3 text-right">{{ t("cashier.subtotal") }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-border/50">
                  <tr
                    v-for="item in breakdownList"
                    :key="item.method"
                    class="hover:bg-muted/20 transition-colors"
                  >
                    <!-- Method Name & Tag -->
                    <td class="py-2 pl-3 pr-2">
                      <div class="flex items-center gap-1.5">
                        <div class="size-5 rounded flex items-center justify-center shrink-0" :class="getMethodConfig(item.method).badgeClass">
                          <component :is="getMethodConfig(item.method).icon" class="size-3" />
                        </div>
                        <div class="min-w-0">
                          <p class="font-medium text-foreground text-[11px] leading-tight truncate">
                            {{ getMethodConfig(item.method).shortLabel }}
                          </p>
                          <span class="text-[9px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {{ item.category }}
                          </span>
                        </div>
                      </div>
                    </td>

                    <!-- Count & Percentage -->
                    <td class="py-2 px-1.5 text-center whitespace-nowrap">
                      <span class="font-mono font-medium text-foreground text-[11px]">
                        {{ item.count }}
                      </span>
                      <span class="text-[10px] text-muted-foreground ml-1">
                        ({{ item.percentage }}%)
                      </span>
                    </td>

                    <!-- Amount Subtotal -->
                    <td class="py-2 pl-1.5 pr-3 text-right font-mono font-semibold text-foreground text-[11px] whitespace-nowrap">
                      {{ formatMoney(item.amount, summary.currencyCode) }}
                    </td>
                  </tr>

                  <tr v-if="breakdownList.length === 0">
                    <td colspan="3" class="py-6 text-center text-xs text-muted-foreground">
                      {{ t("cashier.no_payments_in_shift") }}
                    </td>
                  </tr>
                </tbody>
                <!-- Table Footer Total -->
                <tfoot v-if="breakdownList.length > 0" class="bg-muted/40 border-t border-border/80 font-semibold text-[11px] sticky bottom-0 z-10">
                  <tr>
                    <td class="py-2 pl-3 pr-2 text-foreground">{{ t("cashier.gross_takings") }}</td>
                    <td class="py-2 px-1.5 text-center font-mono">{{ summary.summary?.totalTransactions ?? summary.transactions.length }}</td>
                    <td class="py-2 pl-1.5 pr-3 text-right font-mono text-primary font-bold">
                      {{ formatMoney(summary.summary?.totalGross ?? summary.totalGross, summary.currencyCode) }}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>

          <!-- Right Sub-Panel: Itemized Patient & Transaction Ledger (8 cols on lg) -->
          <div class="lg:col-span-8 rounded-lg border border-border/80 bg-surface shadow-2xs overflow-hidden flex flex-col h-full">
            
            <!-- Toolbar: Header & Search & Quick Pills -->
            <div class="p-3 border-b border-border/60 bg-muted/20 space-y-2.5 shrink-0">
              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                  <h3 class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                    <Receipt class="size-3.5 text-primary" />
                    {{ t("cashier.itemized_ledger_title") }}
                  </h3>
                  <p class="text-[10px] text-muted-foreground">
                    {{ t("cashier.showing_transactions_count", { count: filteredTransactions.length, total: summary.transactions.length, patients: uniquePatientsInFiltered }) }}
                  </p>
                </div>

                <!-- Search Input -->
                <div class="relative w-full sm:w-56">
                  <Search class="size-3 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    v-model="searchQuery"
                    type="text"
                    :placeholder="t('cashier.search_ledger_placeholder')"
                    class="h-7 pl-7 pr-6 text-xs bg-surface"
                  />
                  <button
                    v-if="searchQuery"
                    type="button"
                    class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer"
                    @click="searchQuery = ''"
                  >
                    <X class="size-3" />
                  </button>
                </div>
              </div>

              <!-- Quick-Filter Tender Pills -->
              <div class="flex items-center gap-1.5 overflow-x-auto pb-0.5 no-scrollbar">
                <button
                  v-for="pill in filterPills"
                  :key="pill.id"
                  type="button"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10.5px] font-medium transition-all whitespace-nowrap cursor-pointer border"
                  :class="[
                    selectedMethodFilter === pill.id
                      ? 'bg-primary text-primary-foreground border-primary shadow-2xs font-semibold'
                      : 'bg-surface text-muted-foreground border-border/80 hover:bg-muted/50 hover:text-foreground'
                  ]"
                  @click="selectedMethodFilter = pill.id"
                >
                  <span>{{ pill.label }}</span>
                  <span
                    class="px-1 py-0.2 rounded text-[9px] font-mono font-bold"
                    :class="selectedMethodFilter === pill.id ? 'bg-primary-foreground/20 text-primary-foreground' : 'bg-muted text-muted-foreground'"
                  >
                    {{ pill.count }}
                  </span>
                </button>
              </div>
            </div>

            <!-- Ledger Table -->
            <div class="overflow-x-auto overflow-y-auto flex-1 max-h-[380px]">
              <table class="w-full text-xs text-left border-collapse">
                <thead class="bg-muted/50 sticky top-0 z-10 border-b border-border/80 text-[10px] text-muted-foreground uppercase font-semibold tracking-wider">
                  <tr>
                    <th class="py-2 pl-3 pr-2 w-16">{{ t("cashier.time") }}</th>
                    <th class="py-2 px-2 w-28">{{ t("cashier.receipt_no") }}</th>
                    <th class="py-2 px-2.5">{{ t("cashier.patient_and_mrn") }}</th>
                    <th class="py-2 px-2.5">{{ t("cashier.payment_method_and_ref") }}</th>
                    <th class="py-2 pl-2 pr-3 text-right w-24">{{ t("cashier.amount_due") }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-border/50">
                  <tr
                    v-for="txn in filteredTransactions"
                    :key="txn.id"
                    class="hover:bg-muted/30 transition-colors"
                  >
                    <!-- Time -->
                    <td class="py-2 pl-3 pr-2 text-muted-foreground font-mono text-[11px] whitespace-nowrap">
                      {{ txn.receivedAt ? new Date(txn.receivedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—' }}
                    </td>

                    <!-- Receipt # with 1-click copy -->
                    <td class="py-2 px-2 whitespace-nowrap">
                      <div class="flex items-center gap-1">
                        <span class="font-mono text-[11px] font-semibold text-foreground">
                          {{ txn.receiptNumber || txn.paymentNumber || '—' }}
                        </span>
                        <button
                          v-if="txn.receiptNumber"
                          type="button"
                          class="text-muted-foreground hover:text-foreground cursor-pointer p-0.5 rounded hover:bg-muted"
                          :title="t('cashier.copy_receipt')"
                          @click="copyReceipt(txn.receiptNumber)"
                        >
                          <Check v-if="copiedReceipt === txn.receiptNumber" class="size-3 text-emerald-600" />
                          <Copy v-else class="size-3" />
                        </button>
                      </div>
                    </td>

                    <!-- Patient & MRN -->
                    <td class="py-2 px-2.5">
                      <div class="flex items-center gap-2">
                        <div class="size-5.5 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-[9px] shrink-0 border border-primary/20">
                          {{ (txn.patient?.name || txn.patientName || 'U').charAt(0).toUpperCase() }}
                        </div>
                        <div class="flex flex-col min-w-0">
                          <span class="font-medium text-foreground text-[11px] leading-tight truncate max-w-[150px]">
                            {{ txn.patient?.name || txn.patientName || t("cashier.unknown_patient") }}
                          </span>
                          <span class="font-mono text-[9.5px] text-muted-foreground">
                            {{ txn.patient?.patientNumber || txn.patientNumber || t("cashier.no_mrn") }}
                          </span>
                        </div>
                      </div>
                    </td>

                    <!-- Payment Method & Reference Badges -->
                    <td class="py-2 px-2.5">
                      <div class="flex flex-col gap-1">
                        <div
                          v-for="(m, idx) in txn.methods"
                          :key="idx"
                          class="flex items-center gap-1.5 flex-wrap"
                        >
                          <span
                            class="inline-flex items-center gap-1 px-1.5 py-0.2 rounded border text-[10px] font-medium"
                            :class="getMethodConfig(m.method).badgeClass"
                          >
                            <component :is="getMethodConfig(m.method).icon" class="size-2.5" />
                            {{ getMethodConfig(m.method).shortLabel }}
                          </span>

                          <span v-if="m.reference" class="font-mono text-[9.5px] text-muted-foreground bg-muted px-1.5 py-0.2 rounded border border-border/40">
                            {{ m.reference }}
                          </span>

                          <span v-if="txn.methods.length > 1" class="text-[9.5px] text-muted-foreground font-mono">
                            ({{ formatMoney(m.amount, summary.currencyCode) }})
                          </span>
                        </div>
                      </div>
                    </td>

                    <!-- Amount & Change -->
                    <td class="py-2 pl-2 pr-3 text-right whitespace-nowrap">
                      <span class="font-mono font-bold text-foreground text-xs">
                        {{ formatMoney(txn.amount, summary.currencyCode) }}
                      </span>
                      <div v-if="txn.changeAmount && Number(txn.changeAmount) > 0" class="text-[9.5px] text-muted-foreground font-mono">
                        {{ t("cashier.change") }}: {{ formatMoney(txn.changeAmount, summary.currencyCode) }}
                      </div>
                    </td>
                  </tr>

                  <!-- Empty State -->
                  <tr v-if="filteredTransactions.length === 0">
                    <td colspan="5" class="py-12 text-center text-muted-foreground">
                      <Receipt class="size-7 mx-auto mb-2 opacity-30 text-muted-foreground" />
                      <p class="text-xs font-semibold text-foreground">{{ t("cashier.no_transactions_found") }}</p>
                      <p class="text-[11px] text-muted-foreground mt-0.5">
                        {{ searchQuery || selectedMethodFilter !== 'all' ? t("cashier.no_transactions_filter_hint") : t("cashier.transactions_appear_hint") }}
                      </p>
                      <Button
                        v-if="searchQuery || selectedMethodFilter !== 'all'"
                        variant="ghost"
                        size="sm"
                        class="mt-2.5 h-6.5 text-[11px] text-primary cursor-pointer"
                        @click="searchQuery = ''; selectedMethodFilter = 'all'"
                      >
                        {{ t("cashier.reset_filters") }}
                      </Button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

          </div>

        </div>

      </div>

      <!-- 5. Footer Actions -->
      <DialogFooter class="px-5 py-3 border-t border-border/80 bg-surface/95 flex items-center justify-between">
        <div class="text-xs text-muted-foreground font-medium hidden sm:block">
          <span v-if="summary">
            {{ t("cashier.showing_transactions_count", { count: filteredTransactions.length, total: summary.transactions.length, patients: uniquePatientsInFiltered }) }}
          </span>
        </div>

        <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
          <Button 
            variant="outline" 
            size="sm"
            class="h-8 gap-1.5 cursor-pointer text-xs font-medium" 
            @click="printZReport" 
            :disabled="!summary || summary.transactions.length === 0"
          >
            <Printer class="size-3.5" />
            {{ t("cashier.print_z_report") }}
          </Button>

          <Button 
            size="sm"
            class="h-8 cursor-pointer text-xs px-5 font-semibold" 
            @click="emit('update:open', false)"
          >
            {{ t("cashier.done") }}
          </Button>
        </div>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>

