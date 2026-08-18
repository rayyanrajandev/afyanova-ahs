/** * LabQueuePanel — Laboratory Left-Pane Worklist & Specimen Queue (Volume 2.4
§6) *
============================================================================== *
2027 Modern Enterprise Hospital LIS Worklist: * - Dual-View Mode Switcher: Group
by Patient vs By Specimen/Test * - Real-time Status Counts & Quick Filter
Switcher * - Live Barcode / Specimen Search Bar * - Department / Discipline
Filtering Pills * - Urgency Acuity Badges (STAT, Urgent, Routine) * - Full
Internationalization (i18n) Support */

<script setup lang="ts">
import {
  AlertTriangle,
  Barcode,
  CheckCircle2,
  Clock,
  Filter,
  FlaskConical,
  HeartPulse,
  RefreshCw,
  Search,
  TestTube2,
  User,
  Users,
  X,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import WorklistOrderList, {
  type WorklistOrderItem,
  type WorklistTone,
} from "@/components/common/WorklistOrderList.vue";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import type {
  LaboratoryOrder,
  PatientLabGroup,
  UseLaboratoryOrders,
} from "../composables/useLaboratoryOrders";

const props = defineProps<{
  laboratory: UseLaboratoryOrders;
}>();

const { t } = useI18n({ useScope: "global" });

/**
 * Ids are the catalog's own `category` values, because the discipline filter is
 * now a server query against that column.
 *
 * They used to be a vocabulary this file invented — "biochemistry", "serology" —
 * which the catalog does not use ("clinical_chemistry", "serology_immunology").
 * That mismatch was survivable only while the filtering happened in the browser
 * against a department string the browser had also guessed. Two disciplines the
 * catalog carries had no pill at all.
 */
const DEPARTMENTS = computed(() => [
  { id: "all", label: t("laboratory.dept_all", "All Depts") },
  { id: "hematology", label: t("laboratory.dept_hematology", "Hematology") },
  {
    id: "clinical_chemistry",
    label: t("laboratory.dept_clinical_chemistry", "Clinical Chemistry"),
  },
  {
    id: "parasitology",
    label: t("laboratory.dept_parasitology", "Parasitology"),
  },
  {
    id: "serology_immunology",
    label: t("laboratory.dept_serology_immunology", "Serology & Immunology"),
  },
  {
    id: "microbiology",
    label: t("laboratory.dept_microbiology", "Microbiology"),
  },
  { id: "urinalysis", label: t("laboratory.dept_urinalysis", "Urinalysis") },
  {
    id: "blood_bank_transfusion",
    label: t("laboratory.dept_blood_bank", "Blood Bank"),
  },
]);

function getRelativeTime(dateStr: string): string {
  if (!dateStr) return "";
  const date = new Date(dateStr);
  const diffMs = Date.now() - date.getTime();
  const diffMins = Math.floor(diffMs / (1000 * 60));
  if (diffMins < 1) return "just now";
  if (diffMins < 60) return `${diffMins}m ago`;
  const diffHours = Math.floor(diffMins / 60);
  if (diffHours < 24) return `${diffHours}h ago`;
  return date.toLocaleDateString([], { day: "numeric", month: "short" });
}

/**
 * Where the patient is in the whole visit, not where this order is. Rendered
 * from the shared step vocabulary (stepLabelKey/stepBadgeStatus) rather than a
 * lab-local mapping, so a row here reads exactly as the same patient's row on
 * reception, nursing and clinician.
 */
function visitStageLabel(stage: string | null | undefined): string | null {
  const key = stepLabelKey(stage);

  return key ? t(key) : null;
}

/** Colour follows the shared vocabulary: active contact vs waiting in a queue. */
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

/** A patient group shows the stage of the visit its orders belong to. */
const LAB_TONE: Record<string, WorklistTone> = {
  ordered: "waiting",
  collected: "active",
  in_progress: "progress",
  cancelled: "cancelled",
};

/**
 * A completed test that nobody has signed off is not the same as a released
 * result, and the bench has to be able to tell them apart at a glance.
 */
function itemTone(order: LaboratoryOrder): WorklistTone {
  if (order.status === "completed") {
    return order.verifiedAt ? "verified" : "released";
  }

  return LAB_TONE[order.status] ?? "cancelled";
}

/**
 * The same words the segmented filter above uses. A row that reads "ordered"
 * under a filter chip labelled "Pending" makes the bench translate between two
 * vocabularies for one state.
 */
function itemToneLabel(order: LaboratoryOrder): string {
  switch (order.status) {
    case "ordered":
      return t("laboratory.status_pending", "Pending");
    case "collected":
      return t("laboratory.status_in_lab", "In Lab");
    case "in_progress":
      return t("laboratory.status_testing", "Testing");
    case "completed":
      return order.verifiedAt
        ? t("laboratory.status_verified", "Verified")
        : t("laboratory.status_done", "Done");
    default:
      return t("laboratory.status_cancelled", "Cancelled");
  }
}

function worklistItems(group: PatientLabGroup): WorklistOrderItem[] {
  return group.orders.map((order) => ({
    id: order.id,
    label: order.testName,
    // Department and specimen are what distinguish two tests that read alike.
    detail: [order.department, order.sampleType].filter(Boolean).join(" · "),
    tone: itemTone(order),
    toneLabel: itemToneLabel(order),
  }));
}

function groupVisitStage(group: {
  orders: Array<{ visitStage?: string | null }>;
}): string | null {
  return group.orders.find((order) => order.visitStage)?.visitStage ?? null;
}
</script>

<template>
  <Tabs
    v-model="laboratory.viewMode.value"
    class="flex h-full flex-col overflow-hidden bg-surface"
  >
    <!-- Top Header Tabs (Standardized with Clinician / Nursing Left Pane) -->
    <div class="border-b border-border bg-surface px-3 pt-1 shrink-0">
      <TabsList
        class="h-8 gap-1 bg-transparent p-0 justify-start w-auto border-b-0 -mb-px"
      >
        <TabsTrigger
          value="patient"
          class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
        >
          <Users class="size-3.5" aria-hidden="true" />
          <span>{{ t("laboratory.by_patient", "Patients") }}</span>
          <Badge
            v-if="laboratory.patientGroups.value.length > 0"
            variant="secondary"
            class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
            :class="
              laboratory.viewMode.value === 'patient'
                ? 'bg-primary/15 text-primary font-semibold'
                : 'text-muted-foreground'
            "
          >
            {{ laboratory.patientGroups.value.length }}
          </Badge>
        </TabsTrigger>

        <TabsTrigger
          value="test"
          class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
        >
          <TestTube2 class="size-3.5" aria-hidden="true" />
          <span>{{ t("laboratory.by_specimen", "Specimens") }}</span>
          <Badge
            v-if="laboratory.orders.value.length > 0"
            variant="secondary"
            class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
            :class="
              laboratory.viewMode.value === 'test'
                ? 'bg-primary/15 text-primary font-semibold'
                : 'text-muted-foreground'
            "
          >
            {{ laboratory.orders.value.length }}
          </Badge>
        </TabsTrigger>
      </TabsList>
    </div>

    <!-- Search & Filters Container -->
    <div
      class="shrink-0 p-2.5 space-y-2 border-b border-border/70 bg-surface/50"
    >
      <!-- 1. Status Count Filter Segmented Bar -->
      <div
        class="grid grid-cols-5 gap-0.5 rounded-lg bg-muted/70 p-0.5 text-xs font-medium"
      >
        <!-- 1. All -->
        <button
          type="button"
          class="flex items-center justify-center gap-1 rounded-md py-1 px-1 transition-all cursor-pointer select-none"
          :class="
            laboratory.selectedStatusFilter.value === 'all'
              ? 'bg-card text-foreground font-semibold shadow-2xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="laboratory.selectedStatusFilter.value = 'all'"
        >
          <span class="truncate text-[10.5px]">{{
            t("laboratory.status_all", "All")
          }}</span>
          <span
            class="rounded-full px-1.5 py-0 text-[9.5px] font-mono"
            :class="
              laboratory.selectedStatusFilter.value === 'all'
                ? 'bg-primary/15 text-primary font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ laboratory.statusCounts.value.all }}
          </span>
        </button>

        <!-- 2. Pending -->
        <button
          type="button"
          class="flex items-center justify-center gap-1 rounded-md py-1 px-1 transition-all cursor-pointer select-none"
          :class="
            laboratory.selectedStatusFilter.value === 'ordered'
              ? 'bg-card text-foreground font-semibold shadow-2xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="laboratory.selectedStatusFilter.value = 'ordered'"
        >
          <span class="truncate text-[10.5px]">{{
            t("laboratory.status_pending", "Pending")
          }}</span>
          <span
            class="rounded-full px-1.5 py-0 text-[9.5px] font-mono"
            :class="
              laboratory.selectedStatusFilter.value === 'ordered'
                ? 'bg-amber-500/15 text-amber-600 dark:text-amber-400 font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ laboratory.statusCounts.value.ordered }}
          </span>
        </button>

        <!-- 3. In Lab -->
        <button
          type="button"
          class="flex items-center justify-center gap-1 rounded-md py-1 px-1 transition-all cursor-pointer select-none"
          :class="
            laboratory.selectedStatusFilter.value === 'collected'
              ? 'bg-card text-foreground font-semibold shadow-2xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="laboratory.selectedStatusFilter.value = 'collected'"
        >
          <span class="truncate text-[10.5px]">{{
            t("laboratory.status_in_lab", "In Lab")
          }}</span>
          <span
            class="rounded-full px-1.5 py-0 text-[9.5px] font-mono"
            :class="
              laboratory.selectedStatusFilter.value === 'collected'
                ? 'bg-blue-500/15 text-blue-600 dark:text-blue-400 font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ laboratory.statusCounts.value.collected }}
          </span>
        </button>

        <!-- 4. Testing -->
        <button
          type="button"
          class="flex items-center justify-center gap-1 rounded-md py-1 px-1 transition-all cursor-pointer select-none"
          :class="
            laboratory.selectedStatusFilter.value === 'in_progress'
              ? 'bg-card text-foreground font-semibold shadow-2xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="laboratory.selectedStatusFilter.value = 'in_progress'"
        >
          <span class="truncate text-[10.5px]">{{
            t("laboratory.status_testing", "Testing")
          }}</span>
          <span
            class="rounded-full px-1.5 py-0 text-[9.5px] font-mono"
            :class="
              laboratory.selectedStatusFilter.value === 'in_progress'
                ? 'bg-purple-500/15 text-purple-600 dark:text-purple-400 font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ laboratory.statusCounts.value.in_progress }}
          </span>
        </button>

        <!-- 5. Done -->
        <button
          type="button"
          class="flex items-center justify-center gap-1 rounded-md py-1 px-1 transition-all cursor-pointer select-none"
          :class="
            laboratory.selectedStatusFilter.value === 'completed'
              ? 'bg-card text-foreground font-semibold shadow-2xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="laboratory.selectedStatusFilter.value = 'completed'"
        >
          <span class="truncate text-[10.5px]">{{
            t("laboratory.status_done", "Done")
          }}</span>
          <span
            class="rounded-full px-1.5 py-0 text-[9.5px] font-mono"
            :class="
              laboratory.selectedStatusFilter.value === 'completed'
                ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ laboratory.statusCounts.value.completed }}
          </span>
        </button>
      </div>

      <!-- 2. Department Filter Pills -->
      <div class="flex items-center gap-1 overflow-x-auto pb-0.5 no-scrollbar">
        <button
          v-for="dept in DEPARTMENTS"
          :key="dept.id"
          type="button"
          class="rounded-full px-2.5 py-0.5 text-[10.5px] font-medium whitespace-nowrap border transition-all cursor-pointer shrink-0"
          :class="[
            laboratory.selectedDepartmentFilter.value === dept.id
              ? 'border-primary bg-primary text-primary-foreground font-bold shadow-2xs'
              : 'border-border/80 bg-surface text-muted-foreground hover:text-foreground hover:bg-muted/40',
          ]"
          @click="
            laboratory.selectedDepartmentFilter.value =
              laboratory.selectedDepartmentFilter.value === dept.id
                ? 'all'
                : dept.id
          "
        >
          {{ dept.label }}
        </button>
      </div>

      <!-- 3. Search Input (positioned below filters) -->
      <div class="relative">
        <Search
          class="absolute left-2.5 top-2 size-3.5 text-muted-foreground"
        />
        <Input
          v-model="laboratory.searchQuery.value"
          type="search"
          :placeholder="
            t(
              'laboratory.search_placeholder',
              'Search patient, MRN, test, barcode...',
            )
          "
          class="h-7.5 pl-8 text-xs font-medium"
        />
        <button
          v-if="laboratory.searchQuery.value"
          type="button"
          class="absolute right-2 top-2 text-muted-foreground hover:text-foreground cursor-pointer"
          @click="laboratory.searchQuery.value = ''"
        >
          <X class="size-3.5" />
        </button>
      </div>
    </div>

    <!-- Mode A: PATIENT-CENTRIC WORKLIST -->
    <div
      v-if="laboratory.viewMode.value === 'patient'"
      class="flex-1 overflow-y-auto p-2 space-y-2"
    >
      <div v-if="laboratory.isLoadingOrders.value" class="space-y-2 p-1">
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

      <!--
        A failed load and an empty worklist are different answers and used to
        look identical, because a failure quietly rendered demo fixtures.
      -->
      <div
        v-else-if="laboratory.loadFailed.value"
        class="py-8 text-center text-xs text-critical"
      >
        {{ t("laboratory.load_failed", "Could not load the worklist. Retry.") }}
      </div>

      <div
        v-else-if="laboratory.filteredPatientGroups.value.length === 0"
        class="py-8 text-center text-xs text-muted-foreground"
      >
        {{ t("laboratory.no_patients_found", "No matching patients found.") }}
      </div>

      <div
        v-for="group in laboratory.filteredPatientGroups.value"
        :key="group.patientId"
        class="rounded-lg border p-2.5 text-xs transition-all cursor-pointer select-none space-y-1.5"
        :class="[
          laboratory.selectedPatientId.value === group.patientId
            ? 'border-primary bg-primary/10 ring-1 ring-primary/40 shadow-xs'
            : 'border-border/80 bg-surface hover:border-primary/30 hover:bg-muted/30',
        ]"
        @click="laboratory.selectPatient(group.patientId)"
      >
        <!-- Patient Header Row -->
        <div class="flex items-center justify-between gap-1.5">
          <div class="flex items-center gap-2 min-w-0">
            <div
              class="flex size-6 shrink-0 items-center justify-center rounded-full bg-secondary text-foreground font-bold text-[10px]"
            >
              {{ group.patientName.slice(0, 2).toUpperCase() }}
            </div>
            <span class="font-bold text-foreground truncate text-[12px]">
              {{ group.patientName }}
            </span>
          </div>

          <Badge
            v-if="group.highestPriority === 'stat'"
            variant="outline"
            class="bg-rose-500/15 border-rose-500/50 text-rose-600 font-mono font-bold text-[9px] uppercase px-1 py-0 animate-pulse shrink-0"
          >
            {{ t("laboratory.stat_priority", "STAT") }}
          </Badge>
          <Badge
            v-else-if="group.highestPriority === 'urgent'"
            variant="outline"
            class="bg-amber-500/15 border-amber-500/50 text-amber-600 font-mono font-bold text-[9px] uppercase px-1 py-0 shrink-0"
          >
            {{ t("laboratory.urgent_priority", "URGENT") }}
          </Badge>
        </div>

        <!-- Demographics & Tests Count -->
        <div
          class="flex items-center justify-between text-[10.5px] text-muted-foreground font-mono"
        >
          <span>{{ group.patientMrn }} · {{ group.patientGender }}</span>
          <span class="font-semibold text-primary">
            {{ t("laboratory.test_count", { count: group.totalTests }) }}
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

        <!-- Ordered Tests -->
        <WorklistOrderList
          :items="worklistItems(group)"
          :selected-id="laboratory.selectedOrderId.value"
          @select="laboratory.selectOrder($event)"
        />
      </div>
    </div>

    <!-- Mode B: SPECIMEN / TEST WORKLIST -->
    <div v-else class="flex-1 overflow-y-auto p-2 space-y-1.5">
      <div v-if="laboratory.isLoadingOrders.value" class="space-y-1.5 p-1">
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
        v-else-if="laboratory.loadFailed.value"
        class="py-8 text-center text-xs text-critical"
      >
        {{ t("laboratory.load_failed", "Could not load the worklist. Retry.") }}
      </div>

      <div
        v-else-if="laboratory.filteredOrders.value.length === 0"
        class="py-8 text-center text-xs text-muted-foreground"
      >
        {{
          t(
            "laboratory.no_orders_found",
            "No matching laboratory investigations found.",
          )
        }}
      </div>

      <div
        v-for="order in laboratory.filteredOrders.value"
        :key="order.id"
        class="rounded-lg border p-2.5 text-xs transition-all cursor-pointer select-none"
        :class="[
          laboratory.selectedOrderId.value === order.id
            ? 'border-primary bg-primary/10 ring-1 ring-primary/40 shadow-xs'
            : 'border-border/80 bg-surface hover:border-primary/30 hover:bg-muted/30',
        ]"
        @click="laboratory.selectOrder(order.id)"
      >
        <!-- Top Row: Patient Name & Priority -->
        <div class="flex items-center justify-between gap-1.5">
          <span class="font-bold text-foreground truncate text-[12px]">
            {{ order.patientName }}
          </span>

          <Badge
            v-if="order.priority === 'stat'"
            variant="outline"
            class="bg-rose-500/15 border-rose-500/50 text-rose-600 font-mono font-bold text-[9px] uppercase px-1 py-0 animate-pulse shrink-0"
          >
            {{ t("laboratory.stat_priority", "STAT") }}
          </Badge>
          <Badge
            v-else-if="order.priority === 'urgent'"
            variant="outline"
            class="bg-amber-500/15 border-amber-500/50 text-amber-600 font-mono font-bold text-[9px] uppercase px-1 py-0 shrink-0"
          >
            {{ t("laboratory.urgent_priority", "URGENT") }}
          </Badge>
        </div>

        <!-- Middle Row: Test Name & Code -->
        <div class="mt-1 flex items-center justify-between gap-1 text-[11px]">
          <span class="font-semibold text-primary truncate">
            {{ order.testName }}
          </span>
          <span
            class="font-mono text-[10px] text-muted-foreground shrink-0 bg-secondary px-1 py-0 rounded"
          >
            {{ order.testCode }}
          </span>
        </div>

        <!-- Bottom Row: Department, MRN & Time Ago -->
        <div
          class="mt-1.5 flex items-center justify-between gap-1 text-[10px] text-muted-foreground border-t border-border/40 pt-1 font-mono"
        >
          <span>{{ order.patientMrn }}</span>
          <span>{{ order.department }}</span>
          <span>{{ getRelativeTime(order.createdAt) }}</span>
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
