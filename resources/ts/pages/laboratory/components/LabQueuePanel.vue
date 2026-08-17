/**
 * LabQueuePanel — Laboratory Left-Pane Worklist & Specimen Queue (Volume 2.4 §6)
 * ==============================================================================
 * 2027 Modern Enterprise Hospital LIS Worklist:
 * - Dual-View Mode Switcher: Group by Patient vs By Specimen/Test
 * - Real-time Status Counts & Quick Filter Switcher
 * - Live Barcode / Specimen Search Bar
 * - Department / Discipline Filtering Pills
 * - Urgency Acuity Badges (STAT, Urgent, Routine)
 * - Full Internationalization (i18n) Support
 */

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
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import type { UseLaboratoryOrders } from "../composables/useLaboratoryOrders";

const props = defineProps<{
  laboratory: UseLaboratoryOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const DEPARTMENTS = computed(() => [
  { id: "all", label: t("laboratory.dept_all", "All Depts") },
  { id: "hematology", label: t("laboratory.dept_hematology", "Hematology") },
  { id: "biochemistry", label: t("laboratory.dept_biochemistry", "Biochemistry") },
  { id: "parasitology", label: t("laboratory.dept_parasitology", "Parasitology") },
  { id: "serology", label: t("laboratory.dept_serology", "Serology") },
  { id: "urinalysis", label: t("laboratory.dept_urinalysis", "Urinalysis") },
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
function groupVisitStage(group: { orders: Array<{ visitStage?: string | null }> }): string | null {
  return group.orders.find((order) => order.visitStage)?.visitStage ?? null;
}

</script>

<template>
  <div class="flex h-full flex-col overflow-hidden bg-background border-r border-border">
    <!-- Header -->
    <header class="flex shrink-0 items-center justify-between border-b border-border bg-surface px-3 py-2">
      <div class="flex items-center gap-1.5">
        <div class="flex size-6 items-center justify-center rounded-md bg-primary/10 text-primary">
          <FlaskConical class="size-3.5" />
        </div>
        <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
          {{ t('laboratory.worklist', 'Lab Worklist') }}
        </h3>
      </div>

      <Button
        variant="ghost"
        size="sm"
        class="h-6 text-[11px] px-1.5 text-muted-foreground hover:text-foreground cursor-pointer gap-1"
        :disabled="laboratory.isLoadingOrders.value"
        @click="laboratory.fetchOrders"
      >
        <RefreshCw class="size-3" :class="{ 'animate-spin': laboratory.isLoadingOrders.value }" />
        <span>{{ t("common.refresh", "Refresh") }}</span>
      </Button>
    </header>

    <!-- Dual-View Mode Switcher (2027 Enterprise LIS) -->
    <div class="shrink-0 p-2.5 pb-0 bg-surface/50 border-b border-border/60">
      <div class="grid grid-cols-2 gap-1 p-0.5 rounded-lg border border-border bg-muted/30 text-xs">
        <button
          type="button"
          class="flex items-center justify-center gap-1.5 py-1 px-2 rounded-md font-semibold transition-all cursor-pointer select-none"
          :class="[
            laboratory.viewMode.value === 'patient'
              ? 'bg-primary text-primary-foreground shadow-2xs font-bold'
              : 'text-muted-foreground hover:text-foreground',
          ]"
          @click="laboratory.viewMode.value = 'patient'"
        >
          <Users class="size-3.5" />
          <span class="text-[11px]">{{ t('laboratory.by_patient', 'By Patient') }} ({{ laboratory.patientGroups.value.length }})</span>
        </button>

        <button
          type="button"
          class="flex items-center justify-center gap-1.5 py-1 px-2 rounded-md font-semibold transition-all cursor-pointer select-none"
          :class="[
            laboratory.viewMode.value === 'test'
              ? 'bg-primary text-primary-foreground shadow-2xs font-bold'
              : 'text-muted-foreground hover:text-foreground',
          ]"
          @click="laboratory.viewMode.value = 'test'"
        >
          <TestTube2 class="size-3.5" />
          <span class="text-[11px]">{{ t('laboratory.by_specimen', 'By Specimen') }} ({{ laboratory.orders.value.length }})</span>
        </button>
      </div>
    </div>

    <!-- Search & Filters Container -->
    <div class="shrink-0 p-2.5 space-y-2 border-b border-border/70 bg-surface/50">
      <!-- Search Input -->
      <div class="relative">
        <Search class="absolute left-2.5 top-2 size-3.5 text-muted-foreground" />
        <Input
          v-model="laboratory.searchQuery.value"
          type="search"
          :placeholder="t('laboratory.search_placeholder', 'Search patient, MRN, test, barcode...')"
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

      <!-- Status Count Tabs -->
      <div class="grid grid-cols-5 gap-1 text-center">
        <button
          type="button"
          class="flex flex-col items-center justify-center py-1 rounded-md border text-[10px] font-semibold transition-all cursor-pointer"
          :class="[
            laboratory.selectedStatusFilter.value === 'all'
              ? 'border-primary bg-primary text-primary-foreground font-bold shadow-2xs'
              : 'border-border/80 bg-surface hover:bg-muted text-muted-foreground',
          ]"
          @click="laboratory.selectedStatusFilter.value = 'all'"
        >
          <span class="font-mono text-xs">{{ laboratory.statusCounts.value.all }}</span>
          <span class="text-[9px] uppercase">{{ t('laboratory.status_all', 'All') }}</span>
        </button>

        <button
          type="button"
          class="flex flex-col items-center justify-center py-1 rounded-md border text-[10px] font-semibold transition-all cursor-pointer"
          :class="[
            laboratory.selectedStatusFilter.value === 'ordered'
              ? 'border-amber-500 bg-amber-500 text-white font-bold shadow-2xs'
              : 'border-border/80 bg-surface hover:bg-muted text-muted-foreground',
          ]"
          @click="laboratory.selectedStatusFilter.value = 'ordered'"
        >
          <span class="font-mono text-xs">{{ laboratory.statusCounts.value.ordered }}</span>
          <span class="text-[9px] uppercase">{{ t('laboratory.status_pending', 'Pending') }}</span>
        </button>

        <button
          type="button"
          class="flex flex-col items-center justify-center py-1 rounded-md border text-[10px] font-semibold transition-all cursor-pointer"
          :class="[
            laboratory.selectedStatusFilter.value === 'sample_collected'
              ? 'border-blue-500 bg-blue-500 text-white font-bold shadow-2xs'
              : 'border-border/80 bg-surface hover:bg-muted text-muted-foreground',
          ]"
          @click="laboratory.selectedStatusFilter.value = 'sample_collected'"
        >
          <span class="font-mono text-xs">{{ laboratory.statusCounts.value.sample_collected }}</span>
          <span class="text-[9px] uppercase">{{ t('laboratory.status_in_lab', 'In Lab') }}</span>
        </button>

        <button
          type="button"
          class="flex flex-col items-center justify-center py-1 rounded-md border text-[10px] font-semibold transition-all cursor-pointer"
          :class="[
            laboratory.selectedStatusFilter.value === 'in_progress'
              ? 'border-purple-500 bg-purple-500 text-white font-bold shadow-2xs'
              : 'border-border/80 bg-surface hover:bg-muted text-muted-foreground',
          ]"
          @click="laboratory.selectedStatusFilter.value = 'in_progress'"
        >
          <span class="font-mono text-xs">{{ laboratory.statusCounts.value.in_progress }}</span>
          <span class="text-[9px] uppercase">{{ t('laboratory.status_testing', 'Testing') }}</span>
        </button>

        <button
          type="button"
          class="flex flex-col items-center justify-center py-1 rounded-md border text-[10px] font-semibold transition-all cursor-pointer"
          :class="[
            laboratory.selectedStatusFilter.value === 'completed'
              ? 'border-emerald-500 bg-emerald-500 text-white font-bold shadow-2xs'
              : 'border-border/80 bg-surface hover:bg-muted text-muted-foreground',
          ]"
          @click="laboratory.selectedStatusFilter.value = 'completed'"
        >
          <span class="font-mono text-xs">{{ laboratory.statusCounts.value.completed }}</span>
          <span class="text-[9px] uppercase">{{ t('laboratory.status_done', 'Done') }}</span>
        </button>
      </div>

      <!-- Department Filter Pills -->
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
          @click="laboratory.selectedDepartmentFilter.value = (laboratory.selectedDepartmentFilter.value === dept.id ? 'all' : dept.id)"
        >
          {{ dept.label }}
        </button>
      </div>
    </div>

    <!-- Mode A: PATIENT-CENTRIC WORKLIST -->
    <div v-if="laboratory.viewMode.value === 'patient'" class="flex-1 overflow-y-auto p-2 space-y-2">
      <div v-if="laboratory.isLoadingOrders.value" class="py-8 text-center text-xs text-muted-foreground">
        <RefreshCw class="size-4 animate-spin mx-auto mb-1 text-primary" />
        <span>{{ t('laboratory.loading_patient_queue', 'Loading patient laboratory queue...') }}</span>
      </div>

      <div v-else-if="laboratory.filteredPatientGroups.value.length === 0" class="py-8 text-center text-xs text-muted-foreground">
        {{ t('laboratory.no_patients_found', 'No matching patients found.') }}
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
            <div class="flex size-6 shrink-0 items-center justify-center rounded-full bg-secondary text-foreground font-bold text-[10px]">
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
            {{ t('laboratory.stat_priority', 'STAT') }}
          </Badge>
          <Badge
            v-else-if="group.highestPriority === 'urgent'"
            variant="outline"
            class="bg-amber-500/15 border-amber-500/50 text-amber-600 font-mono font-bold text-[9px] uppercase px-1 py-0 shrink-0"
          >
            {{ t('laboratory.urgent_priority', 'URGENT') }}
          </Badge>
        </div>

        <!-- Demographics & Tests Count -->
        <div class="flex items-center justify-between text-[10.5px] text-muted-foreground font-mono">
          <span>{{ group.patientMrn }} · {{ group.patientGender }}</span>
          <span class="font-semibold text-primary">
            {{ t('laboratory.test_count', { count: group.totalTests }) }}
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

        <!-- Ordered Tests Pills Summary -->
        <div class="flex flex-wrap gap-1 pt-1 border-t border-border/40">
          <button
            v-for="order in group.orders"
            :key="order.id"
            type="button"
            class="rounded px-1.5 py-0.5 text-[10px] font-mono border transition-all text-left flex items-center gap-1 cursor-pointer"
            :class="[
              laboratory.selectedOrderId.value === order.id
                ? 'border-primary bg-primary text-primary-foreground font-bold'
                : 'border-border/80 bg-background text-foreground hover:border-primary/50',
            ]"
            @click.stop="laboratory.selectOrder(order.id)"
          >
            <span
              class="size-1.5 rounded-full shrink-0"
              :class="{
                'bg-amber-500': order.status === 'ordered',
                'bg-blue-500': order.status === 'sample_collected',
                'bg-purple-500': order.status === 'in_progress',
                'bg-emerald-500': order.status === 'completed',
              }"
            />
            <span class="truncate max-w-[130px]">{{ order.testName }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Mode B: SPECIMEN / TEST WORKLIST -->
    <div v-else class="flex-1 overflow-y-auto p-2 space-y-1.5">
      <div v-if="laboratory.isLoadingOrders.value" class="py-8 text-center text-xs text-muted-foreground">
        <RefreshCw class="size-4 animate-spin mx-auto mb-1 text-primary" />
        <span>{{ t('laboratory.loading_specimen_worklist', 'Loading specimen worklist...') }}</span>
      </div>

      <div v-else-if="laboratory.filteredOrders.value.length === 0" class="py-8 text-center text-xs text-muted-foreground">
        {{ t('laboratory.no_orders_found', 'No matching laboratory investigations found.') }}
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
            {{ t('laboratory.stat_priority', 'STAT') }}
          </Badge>
          <Badge
            v-else-if="order.priority === 'urgent'"
            variant="outline"
            class="bg-amber-500/15 border-amber-500/50 text-amber-600 font-mono font-bold text-[9px] uppercase px-1 py-0 shrink-0"
          >
            {{ t('laboratory.urgent_priority', 'URGENT') }}
          </Badge>
        </div>

        <!-- Middle Row: Test Name & Code -->
        <div class="mt-1 flex items-center justify-between gap-1 text-[11px]">
          <span class="font-semibold text-primary truncate">
            {{ order.testName }}
          </span>
          <span class="font-mono text-[10px] text-muted-foreground shrink-0 bg-secondary px-1 py-0 rounded">
            {{ order.testCode }}
          </span>
        </div>

        <!-- Bottom Row: Department, MRN & Time Ago -->
        <div class="mt-1.5 flex items-center justify-between gap-1 text-[10px] text-muted-foreground border-t border-border/40 pt-1 font-mono">
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
  </div>
</template>
