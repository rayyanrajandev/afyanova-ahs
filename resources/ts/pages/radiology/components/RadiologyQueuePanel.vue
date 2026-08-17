/**
 * RadiologyQueuePanel — Modern 2027 Enterprise Diagnostic Imaging Worklist
 * =========================================================================
 * - Dual-View Mode Switcher: Group by Patient vs By Individual Study
 * - Real-time Status Counts Segmented Filter Bar
 * - Modality Quick Discipline Pills (US, XR, CT, MR, MAMMO)
 * - Live Instant Search Bar (Name, MRN, Study, Accession)
 * - Clinical Acuity Badges (STAT Pulsing, Urgent, Routine)
 * - Patient Visit Stage Tracking and Scheduled Slot Times
 */

<script setup lang="ts">
import {
  Activity,
  AlertTriangle,
  Clock,
  Filter,
  Layers,
  RefreshCw,
  ScanLine,
  Search,
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
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import type {
  PatientRadiologyGroup,
  RadiologyModality,
  RadiologyOrder,
  RadiologyOrderStatus,
  UseRadiologyOrders,
} from "../composables/useRadiologyOrders";

const props = defineProps<{
  radiology: UseRadiologyOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const MODALITIES = computed<Array<{ id: RadiologyModality | "all"; label: string; short: string }>>(() => [
  { id: "all", label: t("radiology.modality_all", "All"), short: "ALL" },
  { id: "ultrasound", label: t("radiology.modality_us", "Ultrasound"), short: "US" },
  { id: "xray", label: t("radiology.modality_xr", "X-Ray"), short: "XR" },
  { id: "ct", label: t("radiology.modality_ct", "CT Scan"), short: "CT" },
  { id: "mri", label: t("radiology.modality_mr", "MRI"), short: "MRI" },
  { id: "mammography", label: t("radiology.modality_mammo", "Mammography"), short: "MAMMO" },
]);

const STATUS_TABS: Array<{ value: RadiologyOrderStatus | "all"; labelKey: string; fallback: string }> = [
  { value: "all", labelKey: "radiology.status_all", fallback: "All" },
  { value: "ordered", labelKey: "radiology.status_ordered", fallback: "Ordered" },
  { value: "scheduled", labelKey: "radiology.status_scheduled", fallback: "Booked" },
  { value: "in_progress", labelKey: "radiology.status_in_progress", fallback: "Scanning" },
  { value: "completed", labelKey: "radiology.status_completed", fallback: "Reported" },
];

function modalityBadgeClass(modality: string): string {
  switch (modality?.toLowerCase()) {
    case "ultrasound":
    case "us":
      return "border-sky-500/40 text-sky-700 dark:text-sky-300 bg-sky-500/10";
    case "xray":
    case "xr":
      return "border-indigo-500/40 text-indigo-700 dark:text-indigo-300 bg-indigo-500/10";
    case "ct":
      return "border-purple-500/40 text-purple-700 dark:text-purple-300 bg-purple-500/10";
    case "mri":
    case "mr":
      return "border-emerald-500/40 text-emerald-700 dark:text-emerald-300 bg-emerald-500/10";
    case "mammography":
    case "mammo":
      return "border-pink-500/40 text-pink-700 dark:text-pink-300 bg-pink-500/10";
    default:
      return "border-border text-foreground bg-muted";
  }
}

/** Where the patient is in the clinic */
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

function studyStatusClass(status: RadiologyOrderStatus): string {
  switch (status) {
    case "ordered":
      return "border-amber-500/40 text-amber-700 dark:text-amber-300 bg-amber-500/10";
    case "scheduled":
      return "border-blue-500/40 text-blue-700 dark:text-blue-300 bg-blue-500/10";
    case "in_progress":
      return "border-purple-500/40 text-purple-700 dark:text-purple-300 bg-purple-500/10";
    case "completed":
      return "border-emerald-500/40 text-emerald-700 dark:text-emerald-300 bg-emerald-500/10";
    default:
      return "border-border text-muted-foreground bg-secondary";
  }
}

function slotLabel(order: RadiologyOrder): string | null {
  if (!order.scheduledFor) return null;
  try {
    return new Date(order.scheduledFor).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  } catch {
    return null;
  }
}

function getRelativeTime(dateStr: string | null | undefined): string {
  if (!dateStr) return "";
  try {
    const date = new Date(dateStr);
    const diffMs = Date.now() - date.getTime();
    const diffMins = Math.floor(diffMs / (1000 * 60));
    if (diffMins < 1) return "just now";
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    return date.toLocaleDateString([], { day: "numeric", month: "short" });
  } catch {
    return "";
  }
}
</script>

<template>
  <Tabs v-model="radiology.viewMode.value" class="flex h-full flex-col overflow-hidden bg-surface">
    <!-- Top Header Tabs (Standardized with Laboratory / Clinician Left Pane) -->
    <div class="border-b border-border bg-surface px-3 pt-1 shrink-0">
      <TabsList class="h-8 gap-1 bg-transparent p-0 justify-start w-auto border-b-0 -mb-px">
        <TabsTrigger
          value="patient"
          class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
        >
          <Users class="size-3.5" aria-hidden="true" />
          <span>{{ t('radiology.by_patient', 'Patients') }}</span>
          <Badge
            v-if="radiology.patientGroups.value.length > 0"
            variant="secondary"
            class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
            :class="radiology.viewMode.value === 'patient' ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground'"
          >
            {{ radiology.patientGroups.value.length }}
          </Badge>
        </TabsTrigger>

        <TabsTrigger
          value="study"
          class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
        >
          <ScanLine class="size-3.5" aria-hidden="true" />
          <span>{{ t('radiology.by_study', 'Studies') }}</span>
          <Badge
            v-if="radiology.orders.value.length > 0"
            variant="secondary"
            class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
            :class="radiology.viewMode.value === 'study' ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground'"
          >
            {{ radiology.orders.value.length }}
          </Badge>
        </TabsTrigger>
      </TabsList>
    </div>

    <!-- Search & Filters Container -->
    <div class="shrink-0 p-2.5 space-y-2 border-b border-border/70 bg-surface/50">
      <!-- 1. Status Count Filter Segmented Bar -->
      <div class="grid grid-cols-5 gap-0.5 rounded-lg bg-muted/70 p-0.5 text-xs font-medium">
        <button
          v-for="statusTab in STATUS_TABS"
          :key="statusTab.value"
          type="button"
          class="flex items-center justify-center gap-1 rounded-md py-1 px-0.5 transition-all cursor-pointer select-none"
          :class="
            radiology.selectedStatusFilter.value === statusTab.value
              ? 'bg-card text-foreground font-semibold shadow-2xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="radiology.selectedStatusFilter.value = statusTab.value"
        >
          <span class="truncate text-[10px]">{{ t(statusTab.labelKey, statusTab.fallback) }}</span>
          <span
            class="rounded-full px-1 py-0 text-[9px] font-mono"
            :class="
              radiology.selectedStatusFilter.value === statusTab.value
                ? 'bg-primary/15 text-primary font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ radiology.statusCounts.value[statusTab.value] ?? 0 }}
          </span>
        </button>
      </div>

      <!-- 2. Modality Quick Discipline Pills -->
      <div class="flex items-center gap-1 overflow-x-auto pb-0.5 text-[10.5px] no-scrollbar">
        <button
          v-for="mod in MODALITIES"
          :key="mod.id"
          type="button"
          class="px-2 py-0.5 rounded-full border transition-all shrink-0 cursor-pointer text-[10px] font-semibold"
          :class="
            radiology.selectedModalityFilter.value === mod.id
              ? 'bg-primary text-primary-foreground border-primary shadow-2xs'
              : 'border-border/80 bg-surface text-muted-foreground hover:border-primary/40 hover:text-foreground'
          "
          @click="radiology.selectedModalityFilter.value = mod.id"
        >
          {{ mod.label }}
        </button>
      </div>

      <!-- 3. Search Bar with Instant Clear -->
      <div class="relative">
        <Search class="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground pointer-events-none" />
        <Input
          v-model="radiology.searchQuery.value"
          type="search"
          :placeholder="t('radiology.search_placeholder', 'Search patient name, MRN, study...')"
          class="h-7 pl-8 pr-7 text-xs bg-surface border-border/80 focus-visible:ring-1"
        />
        <button
          v-if="radiology.searchQuery.value"
          type="button"
          class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer p-0.5 rounded"
          :aria-label="t('radiology.clear_search', 'Clear search')"
          @click="radiology.searchQuery.value = ''"
        >
          <X class="size-3.5" />
        </button>
      </div>
    </div>

    <!-- Worklist Content -->
    <div class="flex-1 overflow-y-auto p-2 space-y-1.5">
      <!-- Loading Skeleton / Shimmer -->
      <div v-if="radiology.isLoadingOrders.value && radiology.orders.value.length === 0" class="space-y-2 p-1">
        <div v-for="i in 5" :key="i" class="h-20 rounded-lg border border-border/60 bg-muted/40 animate-pulse" />
      </div>

      <!-- VIEW 1: BY PATIENT -->
      <div v-else-if="radiology.viewMode.value === 'patient'" class="space-y-1.5">
        <div
          v-if="radiology.filteredPatientGroups.value.length === 0"
          class="py-12 px-4 text-center text-xs text-muted-foreground flex flex-col items-center gap-2"
        >
          <Users class="size-8 text-muted-foreground/40 stroke-1" />
          <p class="font-medium text-foreground">{{ t('radiology.no_patients_found', 'No patients matching filters') }}</p>
          <p class="text-[11px] text-muted-foreground max-w-[200px]">
            {{ t('radiology.try_adjusting_filters', 'Try selecting "All" or clearing the search box.') }}
          </p>
        </div>

        <button
          v-for="group in radiology.filteredPatientGroups.value"
          :key="group.patientId"
          type="button"
          class="w-full flex flex-col gap-1.5 rounded-lg border p-2.5 text-left transition-all cursor-pointer relative overflow-hidden group"
          :class="[
            radiology.selectedOrder.value?.patientId === group.patientId
              ? 'border-primary bg-primary/10 ring-1 ring-primary/40 shadow-xs'
              : 'border-border/80 bg-surface hover:border-primary/30 hover:bg-muted/30',
          ]"
          @click="radiology.selectPatient(group.patientId)"
        >
          <!-- Top Row: Patient Name & Acuity Badge -->
          <div class="flex items-center justify-between gap-1.5">
            <div class="flex items-center gap-1.5 min-w-0">
              <span class="truncate text-[12px] font-bold text-foreground">
                {{ group.patientName }}
              </span>
            </div>

            <!-- Acuity indicator -->
            <Badge
              v-if="group.highestPriority === 'stat'"
              variant="outline"
              class="shrink-0 animate-pulse border-rose-500/50 bg-rose-500/15 px-1.5 py-0 font-mono text-[9px] font-bold uppercase text-rose-600 dark:text-rose-400"
            >
              {{ t('radiology.priority_stat', 'STAT') }}
            </Badge>
            <Badge
              v-else-if="group.highestPriority === 'urgent'"
              variant="outline"
              class="shrink-0 border-amber-500/50 bg-amber-500/15 px-1.5 py-0 font-mono text-[9px] font-bold uppercase text-amber-600 dark:text-amber-400"
            >
              {{ t('radiology.priority_urgent', 'URGENT') }}
            </Badge>
          </div>

          <!-- Middle Row: MRN, Age/Gender, Time -->
          <div class="flex items-center justify-between gap-1 font-mono text-[10.5px] text-muted-foreground">
            <span class="text-primary font-semibold truncate">{{ group.patientMrn }}</span>
            <span v-if="group.patientAge || group.patientGender">
              {{ group.patientAge ? `${group.patientAge}y` : '' }} {{ group.patientGender || '' }}
            </span>
            <span v-if="group.latestOrderedAt" class="text-[9.5px]">
              {{ getRelativeTime(group.latestOrderedAt) }}
            </span>
          </div>

          <!-- Studies Summary Chips -->
          <div class="flex flex-wrap items-center gap-1 pt-0.5">
            <span class="text-[10px] font-semibold text-muted-foreground">
              {{ group.totalStudies }} {{ group.totalStudies === 1 ? 'Study' : 'Studies' }}:
            </span>
            <span
              v-for="mod in group.modalities"
              :key="mod"
              class="px-1.5 py-0 rounded text-[9.5px] font-mono font-bold uppercase"
              :class="modalityBadgeClass(mod)"
            >
              {{ mod }}
            </span>
          </div>

          <!-- Bottom Status Pill Row -->
          <div class="flex items-center justify-between gap-1 border-t border-border/40 pt-1 text-[9.5px] font-mono">
            <div class="flex items-center gap-1.5">
              <span v-if="group.orderedCount > 0" class="text-amber-600 font-semibold">
                {{ group.orderedCount }} {{ t('radiology.status_ordered', 'Ordered') }}
              </span>
              <span v-if="group.scheduledCount > 0" class="text-blue-600 font-semibold">
                {{ group.scheduledCount }} {{ t('radiology.status_scheduled', 'Booked') }}
              </span>
              <span v-if="group.inProgressCount > 0" class="text-purple-600 font-semibold">
                {{ group.inProgressCount }} {{ t('radiology.status_in_progress', 'Scanning') }}
              </span>
              <span v-if="group.completedCount > 0" class="text-emerald-600 font-semibold">
                {{ group.completedCount }} {{ t('radiology.status_completed', 'Reported') }}
              </span>
            </div>

            <!-- Patient Visit Stage if available -->
            <span
              v-if="visitStageLabel(group.orders[0]?.visitStage)"
              class="px-1.5 py-0 rounded border text-[9px] font-sans font-medium"
              :class="visitStageClass(group.orders[0]?.visitStage)"
            >
              {{ visitStageLabel(group.orders[0]?.visitStage) }}
            </span>
          </div>
        </button>
      </div>

      <!-- VIEW 2: BY INDIVIDUAL STUDY -->
      <div v-else class="space-y-1.5">
        <div
          v-if="radiology.worklistOrders.value.length === 0"
          class="py-12 px-4 text-center text-xs text-muted-foreground flex flex-col items-center gap-2"
        >
          <ScanLine class="size-8 text-muted-foreground/40 stroke-1" />
          <p class="font-medium text-foreground">{{ t('radiology.no_studies_found', 'No imaging studies found') }}</p>
          <p class="text-[11px] text-muted-foreground max-w-[200px]">
            {{ t('radiology.try_adjusting_filters', 'Try selecting "All" or clearing the search box.') }}
          </p>
        </div>

        <button
          v-for="order in radiology.worklistOrders.value"
          :key="order.id"
          type="button"
          class="w-full flex flex-col gap-1.5 rounded-lg border p-2.5 text-left transition-all cursor-pointer relative overflow-hidden group"
          :class="[
            radiology.selectedOrderId.value === order.id
              ? 'border-primary bg-primary/10 ring-1 ring-primary/40 shadow-xs'
              : 'border-border/80 bg-surface hover:border-primary/30 hover:bg-muted/30',
          ]"
          @click="radiology.selectOrder(order.id)"
        >
          <!-- Top Row: Patient Name & Acuity Badge -->
          <div class="flex items-center justify-between gap-1.5">
            <span class="truncate text-[12px] font-bold text-foreground">
              {{ order.patientName ?? t('radiology.unknown_patient', 'Unknown Patient') }}
            </span>
            <Badge
              v-if="order.priority === 'stat'"
              variant="outline"
              class="shrink-0 animate-pulse border-rose-500/50 bg-rose-500/15 px-1.5 py-0 font-mono text-[9px] font-bold uppercase text-rose-600 dark:text-rose-400"
            >
              {{ t('radiology.priority_stat', 'STAT') }}
            </Badge>
            <Badge
              v-else-if="order.priority === 'urgent'"
              variant="outline"
              class="shrink-0 border-amber-500/50 bg-amber-500/15 px-1.5 py-0 font-mono text-[9px] font-bold uppercase text-amber-600 dark:text-amber-400"
            >
              {{ t('radiology.priority_urgent', 'URGENT') }}
            </Badge>
          </div>

          <!-- Modality Chip & Study Description -->
          <div class="flex items-center gap-1.5 min-w-0">
            <span
              class="px-1.5 py-0 rounded text-[9px] font-mono font-extrabold uppercase shrink-0"
              :class="modalityBadgeClass(order.modality)"
            >
              {{ order.modality }}
            </span>
            <span class="truncate text-[11px] font-semibold text-foreground">
              {{ order.studyDescription }}
            </span>
          </div>

          <!-- Patient MRN & Accession -->
          <div class="flex items-center justify-between gap-1 font-mono text-[10px] text-muted-foreground">
            <span class="text-primary truncate">{{ order.patientMrn }}</span>
            <span v-if="order.orderNumber" class="text-[9.5px] truncate">
              Acc: {{ order.orderNumber }}
            </span>
          </div>

          <!-- Bottom Row: Study Status & Slot Time & Visit Stage -->
          <div class="flex flex-wrap items-center justify-between gap-1 border-t border-border/40 pt-1">
            <div class="flex items-center gap-1">
              <!-- Study Status -->
              <Badge
                variant="outline"
                class="px-1.5 py-0 text-[9px] font-semibold uppercase font-mono"
                :class="studyStatusClass(order.status)"
              >
                {{ t(`radiology.status_${order.status}`, order.status) }}
              </Badge>

              <!-- Booked Slot -->
              <span
                v-if="slotLabel(order)"
                class="inline-flex items-center gap-0.5 font-mono text-[9px] text-muted-foreground bg-muted/60 px-1 py-0 rounded"
              >
                <Clock class="size-2.5 text-primary" />
                {{ slotLabel(order) }}
              </span>
            </div>

            <!-- Patient Visit Stage -->
            <Badge
              v-if="visitStageLabel(order.visitStage)"
              variant="outline"
              class="px-1.5 py-0 text-[8.5px] font-medium uppercase font-sans"
              :class="visitStageClass(order.visitStage)"
            >
              {{ visitStageLabel(order.visitStage) }}
            </Badge>
          </div>
        </button>
      </div>
    </div>
  </Tabs>
</template>
