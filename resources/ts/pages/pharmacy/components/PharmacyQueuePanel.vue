/** * PharmacyQueuePanel — Left-Pane Dispensing Worklist (Volume 2.6) *
=============================================================== * Standardized
against the laboratory and radiology left panes so a member of * staff who has
used one workspace recognises this one: * - The view switcher IS the tab bar
(shared Tabs/TabsList/TabsTrigger), not a * bespoke button group, and there is
no title block — the workspace is already * named by the app shell. * -
Segmented status bar with live counts, then discipline filter, then search. * -
Rows carry the shared visit-stage badge, so a patient reads the same here as *
on reception, clinician and laboratory. * * Pharmacy's own difference is
`partially_dispensed`: an order can be half * satisfied and stay open. It gets
its own column and its own row treatment, * because "3 of 21 handed over" is the
state a dispenser most needs to see. */

<script setup lang="ts">
import { Pill, RefreshCw, Search, Users, X } from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import WorklistOrderList, {
  type WorklistOrderItem,
  type WorklistTone,
} from "@/components/common/WorklistOrderList.vue";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import type {
  PatientPharmacyGroup,
  PharmacyOrder,
  UsePharmacyOrders,
} from "../composables/usePharmacyOrders";

const props = defineProps<{
  pharmacy: UsePharmacyOrders;
}>();

const { t } = useI18n({ useScope: "global" });

/**
 * Segmented status bar. `partially_dispensed` is present because it is a real
 * resting state here — the patient is still owed medicine and still holds the
 * visit — not an edge case between two others.
 */
const STATUS_FILTERS = computed(() => [
  {
    id: "all",
    label: t("pharmacy.status_all", "All"),
    tone: "bg-primary/15 text-primary",
  },
  {
    id: "pending",
    label: t("pharmacy.status_pending", "Pending"),
    tone: "bg-amber-500/15 text-amber-600 dark:text-amber-400",
  },
  {
    id: "in_preparation",
    label: t("pharmacy.status_in_preparation", "In Prep"),
    tone: "bg-blue-500/15 text-blue-600 dark:text-blue-400",
  },
  {
    id: "partially_dispensed",
    label: t("pharmacy.status_partial", "Partial"),
    tone: "bg-purple-500/15 text-purple-600 dark:text-purple-400",
  },
  {
    id: "dispensed",
    label: t("pharmacy.status_dispensed", "Filled"),
    tone: "bg-emerald-500/15 text-emerald-600 dark:text-emerald-400",
  },
]);

function statusCount(id: string): number {
  const counts = props.pharmacy.statusCounts.value;

  return id === "all" ? (counts.total ?? counts.all ?? 0) : (counts[id] ?? 0);
}

function getRelativeTime(dateStr?: string | null): string {
  if (!dateStr) return "";
  const diffMins = Math.floor(
    (Date.now() - new Date(dateStr).getTime()) / (1000 * 60),
  );
  if (diffMins < 1) return t("common.just_now", "just now");
  if (diffMins < 60) return `${diffMins}m`;
  const diffHours = Math.floor(diffMins / 60);
  if (diffHours < 24) return `${diffHours}h`;

  return new Date(dateStr).toLocaleDateString([], {
    day: "numeric",
    month: "short",
  });
}

/**
 * Where the patient is in the whole visit, not where this prescription is.
 * Read from the shared step vocabulary so a row here matches the same patient's
 * row on every other board.
 */
function visitStageLabel(stage: string | null | undefined): string | null {
  const key = stepLabelKey(stage);

  return key ? t(key) : null;
}

function visitStageClass(stage: string | null | undefined): string {
  switch (stepBadgeStatus(stage)) {
    case "in_progress":
    case "info":
      return "border-primary/30 text-primary bg-primary/10";
    case "success":
    case "complete":
      return "border-success/40 text-success bg-success/10";
    default:
      return "border-warning/40 text-warning bg-warning/10";
  }
}

function groupVisitStage(group: PatientPharmacyGroup): string | null {
  return group.orders.find((order) => order.visitStage)?.visitStage ?? null;
}

/** Colour by dispensing state, shared by the chips and the row badge. */
function statusClass(status: string): string {
  switch (status) {
    case "pending":
      return "border-amber-500/40 text-amber-600 bg-amber-500/10";
    case "in_preparation":
      return "border-blue-500/40 text-blue-600 bg-blue-500/10";
    case "partially_dispensed":
      return "border-purple-500/40 text-purple-600 bg-purple-500/10";
    case "dispensed":
      return "border-emerald-500/40 text-emerald-600 bg-emerald-500/10";
    default:
      return "border-rose-500/40 text-rose-600 bg-rose-500/10";
  }
}

function statusLabel(status: string): string {
  return t(`pharmacy.status_${status}`, status.replace(/_/g, " "));
}

/** "8 of 21" — what is still owed, the question a partial fill raises. */
function dispensedProgress(order: PharmacyOrder): string | null {
  if (order.status !== "partially_dispensed") return null;

  return `${order.quantityDispensed ?? 0} / ${order.quantityPrescribed}`;
}

const PHARMACY_TONE: Record<string, WorklistTone> = {
  pending: "waiting",
  in_preparation: "active",
  partially_dispensed: "progress",
  dispensed: "released",
  cancelled: "cancelled",
};

/**
 * Dose, route and frequency belong on the row, not only in the detail pane:
 * two lines of the same medicine at different strengths is a normal
 * prescription, and the name alone cannot tell them apart. A partial fill
 * replaces them with what is still owed, which is the more urgent fact.
 */
function itemDetail(order: PharmacyOrder): string {
  // Matches how the prescription worklist already renders it: the bare
  // fraction, with "Partial" carried by the status label beside it.
  const progress = dispensedProgress(order);
  if (progress) return progress;

  const dose = [order.doseQuantity, order.doseUnit].filter(Boolean).join("");

  return [dose, order.route, order.frequency].filter(Boolean).join(" · ");
}

/** Verification is a resting state of its own, not a shade of "handed over". */
function itemTone(order: PharmacyOrder): WorklistTone {
  if (order.status === "dispensed" && order.verifiedAt) return "verified";

  return PHARMACY_TONE[order.status] ?? "cancelled";
}

function worklistItems(group: PatientPharmacyGroup): WorklistOrderItem[] {
  return group.orders.map((order) => ({
    id: order.id,
    label:
      order.medicationName ||
      t("pharmacy.unknown_medication", "Unnamed medication"),
    detail: itemDetail(order),
    tone: itemTone(order),
    toneLabel: statusLabel(order.status),
  }));
}
</script>

<template>
  <Tabs
    v-model="pharmacy.viewMode.value"
    class="flex h-full flex-col overflow-hidden bg-surface"
  >
    <!-- Top Header Tabs (Standardized with Laboratory / Clinician / Nursing) -->
    <div class="border-b border-border bg-surface px-3 pt-1 shrink-0">
      <TabsList
        class="h-8 gap-1 bg-transparent p-0 justify-start w-auto border-b-0 -mb-px"
      >
        <TabsTrigger
          value="patient"
          class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
        >
          <Users class="size-3.5" aria-hidden="true" />
          <span>{{ t("pharmacy.by_patient", "Patients") }}</span>
          <Badge
            v-if="pharmacy.groupedOrders.value.length > 0"
            variant="secondary"
            class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
            :class="
              pharmacy.viewMode.value === 'patient'
                ? 'bg-primary/15 text-primary font-semibold'
                : 'text-muted-foreground'
            "
          >
            {{ pharmacy.groupedOrders.value.length }}
          </Badge>
        </TabsTrigger>

        <TabsTrigger
          value="prescription"
          class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
        >
          <Pill class="size-3.5" aria-hidden="true" />
          <span>{{ t("pharmacy.by_prescription", "Prescriptions") }}</span>
          <Badge
            v-if="pharmacy.orders.value.length > 0"
            variant="secondary"
            class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
            :class="
              pharmacy.viewMode.value === 'prescription'
                ? 'bg-primary/15 text-primary font-semibold'
                : 'text-muted-foreground'
            "
          >
            {{ pharmacy.orders.value.length }}
          </Badge>
        </TabsTrigger>
      </TabsList>
    </div>

    <!-- Search & Filters Container -->
    <div
      class="shrink-0 p-2.5 space-y-2 border-b border-border/70 bg-surface/50"
    >
      <!-- Status Count Segmented Bar -->
      <div
        class="grid grid-cols-5 gap-0.5 rounded-lg bg-muted/70 p-0.5 text-xs font-medium"
      >
        <button
          v-for="filter in STATUS_FILTERS"
          :key="filter.id"
          type="button"
          class="flex items-center justify-center gap-1 rounded-md py-1 px-1 transition-all cursor-pointer select-none"
          :class="
            pharmacy.selectedStatusFilter.value === filter.id
              ? 'bg-card text-foreground font-semibold shadow-2xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="pharmacy.selectedStatusFilter.value = filter.id"
        >
          <span class="truncate text-[10.5px]">{{ filter.label }}</span>
          <span
            class="rounded-full px-1.5 py-0 text-[9.5px] font-mono"
            :class="
              pharmacy.selectedStatusFilter.value === filter.id
                ? `${filter.tone} font-bold`
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ statusCount(filter.id) }}
          </span>
        </button>
      </div>

      <!-- Search Input -->
      <div class="relative">
        <Search
          class="absolute left-2.5 top-2 size-3.5 text-muted-foreground"
        />
        <Input
          v-model="pharmacy.searchQuery.value"
          type="search"
          :placeholder="
            t(
              'pharmacy.search_placeholder',
              'Search patient, MRN, medication...',
            )
          "
          class="h-7.5 pl-8 text-xs font-medium"
        />
        <button
          v-if="pharmacy.searchQuery.value"
          type="button"
          class="absolute right-2 top-2 text-muted-foreground hover:text-foreground cursor-pointer"
          @click="pharmacy.searchQuery.value = ''"
        >
          <X class="size-3.5" />
        </button>
      </div>
    </div>

    <!-- Mode A: PATIENT-CENTRIC WORKLIST -->
    <div
      v-if="pharmacy.viewMode.value === 'patient'"
      class="flex-1 overflow-y-auto p-2 space-y-2"
    >
      <!-- Skeleton Loader. The rows below are a v-for, not part of this
           chain, so the condition has to exclude them itself: pharmacy holds
           its worklist at module scope, and re-entering the workspace sets
           isLoadingOrders while the previous rows are still on screen. -->
      <div
        v-if="
          pharmacy.isLoadingOrders.value &&
          pharmacy.groupedOrders.value.length === 0
        "
        class="space-y-2 p-1"
      >
        <div
          v-for="n in 5"
          :key="n"
          class="rounded-lg border border-border/70 bg-card p-3 space-y-2 animate-pulse"
        >
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <div class="size-6 rounded-full bg-muted" />
              <div class="h-3.5 w-24 rounded bg-muted" />
            </div>
            <div class="h-3 w-10 rounded bg-muted" />
          </div>
          <div class="flex items-center justify-between">
            <div class="h-2.5 w-20 rounded bg-muted/80" />
            <div class="h-2.5 w-12 rounded bg-muted/80" />
          </div>
          <div class="flex gap-1 pt-1">
            <div class="h-4 w-16 rounded bg-muted/60" />
            <div class="h-4 w-20 rounded bg-muted/60" />
          </div>
        </div>
      </div>

      <div
        v-else-if="pharmacy.groupedOrders.value.length === 0"
        class="py-8 text-center text-xs text-muted-foreground"
      >
        {{ t("pharmacy.no_patients_found", "No matching patients found.") }}
      </div>

      <div
        v-for="group in pharmacy.groupedOrders.value"
        :key="group.patientId"
        class="rounded-lg border p-2.5 text-xs transition-all cursor-pointer select-none space-y-1.5"
        :class="[
          pharmacy.selectedOrder.value?.patientId === group.patientId
            ? 'border-primary bg-primary/10 ring-1 ring-primary/40 shadow-xs'
            : 'border-border/80 bg-surface hover:border-primary/30 hover:bg-muted/30',
        ]"
        @click="group.orders[0] && pharmacy.selectOrder(group.orders[0].id)"
      >
        <!-- Patient Header Row -->
        <div class="flex items-center justify-between gap-1.5">
          <div class="flex items-center gap-2 min-w-0">
            <div
              class="flex size-6 shrink-0 items-center justify-center rounded-full bg-secondary text-foreground font-bold text-[10px]"
            >
              {{ (group.patientName || "PT").slice(0, 2).toUpperCase() }}
            </div>
            <span class="font-bold text-foreground truncate text-[12px]">
              {{
                group.patientName || t("pharmacy.unknown_patient", "Patient")
              }}
            </span>
          </div>

          <Badge
            v-if="group.highestPriority === 'stat'"
            variant="outline"
            class="bg-rose-500/15 border-rose-500/50 text-rose-600 font-mono font-bold text-[9px] uppercase px-1 py-0 animate-pulse shrink-0"
          >
            STAT
          </Badge>
          <Badge
            v-else-if="group.highestPriority === 'urgent'"
            variant="outline"
            class="bg-amber-500/15 border-amber-500/50 text-amber-600 font-mono font-bold text-[9px] uppercase px-1 py-0 shrink-0"
          >
            URGENT
          </Badge>
        </div>

        <!-- Demographics & Prescription Count -->
        <div
          class="flex items-center justify-between text-[10.5px] text-muted-foreground font-mono"
        >
          <span>{{ group.patientMrn }} · {{ group.patientGender }}</span>
          <span class="font-semibold text-primary">
            {{ group.totalPrescriptions }} item(s)
          </span>
        </div>

        <!-- Where this patient actually is in the visit -->
        <div v-if="visitStageLabel(groupVisitStage(group))" class="flex">
          <Badge
            variant="outline"
            class="text-[9px] font-semibold uppercase px-1.5 py-0 shrink-0"
            :class="visitStageClass(groupVisitStage(group))"
          >
            {{ visitStageLabel(groupVisitStage(group)) }}
          </Badge>
        </div>

        <!-- Prescribed Items List -->
        <WorklistOrderList
          :items="worklistItems(group)"
          :selected-id="pharmacy.selectedOrderId.value"
          @select="pharmacy.selectOrder($event)"
        />
      </div>
    </div>

    <!-- Mode B: PRESCRIPTION WORKLIST -->
    <div v-else class="flex-1 overflow-y-auto p-2 space-y-1.5">
      <!-- Skeleton Loader — same rule as the patient view above. -->
      <div
        v-if="
          pharmacy.isLoadingOrders.value && pharmacy.orders.value.length === 0
        "
        class="space-y-1.5 p-1"
      >
        <div
          v-for="n in 6"
          :key="n"
          class="rounded-lg border border-border/70 bg-card p-2.5 space-y-1.5 animate-pulse"
        >
          <div class="flex items-center justify-between">
            <div class="h-3.5 w-28 rounded bg-muted" />
            <div class="h-3 w-12 rounded bg-muted" />
          </div>
          <div class="flex items-center justify-between">
            <div class="h-2.5 w-20 rounded bg-muted/80" />
            <div class="h-2.5 w-14 rounded bg-muted/80" />
          </div>
        </div>
      </div>

      <div
        v-else-if="pharmacy.orders.value.length === 0"
        class="py-8 text-center text-xs text-muted-foreground"
      >
        {{ t("pharmacy.no_orders_found", "No matching prescriptions found.") }}
      </div>

      <div
        v-for="order in pharmacy.orders.value"
        :key="order.id"
        class="rounded-lg border p-2.5 text-xs transition-all cursor-pointer select-none"
        :class="[
          pharmacy.selectedOrderId.value === order.id
            ? 'border-primary bg-primary/10 ring-1 ring-primary/40 shadow-xs'
            : 'border-border/80 bg-surface hover:border-primary/30 hover:bg-muted/30',
        ]"
        @click="pharmacy.selectOrder(order.id)"
      >
        <!-- Top Row: Patient & Status -->
        <div class="flex items-center justify-between gap-1.5">
          <span class="font-bold text-foreground truncate text-[12px]">
            {{ order.patientName || t("pharmacy.unknown_patient", "Patient") }}
          </span>

          <Badge
            variant="outline"
            class="text-[9px] font-mono uppercase px-1 py-0 shrink-0"
            :class="statusClass(order.status)"
          >
            {{ statusLabel(order.status) }}
          </Badge>
        </div>

        <!-- Middle Row: Medication -->
        <div class="mt-1 flex items-center justify-between gap-1 text-[11px]">
          <span class="font-semibold text-primary truncate">
            {{ order.medicationName }}
          </span>
          <span
            v-if="dispensedProgress(order)"
            class="font-mono text-[10px] shrink-0 bg-purple-500/10 text-purple-600 dark:text-purple-400 px-1 py-0 rounded"
            :title="
              t(
                'pharmacy.partial_progress_hint',
                'Dispensed so far, of the amount prescribed',
              )
            "
          >
            {{ dispensedProgress(order) }}
          </span>
        </div>

        <!-- Bottom Row: MRN, quantity, age -->
        <div
          class="mt-1.5 flex items-center justify-between gap-1 text-[10px] text-muted-foreground border-t border-border/40 pt-1 font-mono"
        >
          <span class="truncate">{{ order.patientMrn }}</span>
          <span class="shrink-0"
            >{{ order.quantityPrescribed }}
            {{ order.prescribedUnit || t("pharmacy.units", "units") }}</span
          >
          <span class="shrink-0">{{ getRelativeTime(order.orderedAt) }}</span>
        </div>

        <!-- Where this patient actually is in the visit -->
        <div v-if="visitStageLabel(order.visitStage)" class="mt-1 flex">
          <Badge
            variant="outline"
            class="text-[9px] font-semibold uppercase px-1.5 py-0 shrink-0"
            :class="visitStageClass(order.visitStage)"
          >
            {{ visitStageLabel(order.visitStage) }}
          </Badge>
        </div>
      </div>
    </div>
  </Tabs>
</template>
