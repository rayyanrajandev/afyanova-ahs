/**
 * DiagnosticOrdersTab — Laboratory & Radiology Order Entry (Volume 2.2 §8)
 * =========================================================================
 * Fast diagnostic order entry for physicians with test search, priority flags,
 * clinical indications, and real-time order tracking.
 */

<script setup lang="ts">
import {
  Activity,
  AlertTriangle,
  Calendar,
  CheckCircle2,
  Clock,
  Eye,
  FlaskConical,
  Loader2,
  Plus,
  Radio,
  Search,
  Send,
  XCircle,
} from "lucide-vue-next";
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  diagnosticOrderStage,
  type LabCatalogItem,
  type PlacedClinicalOrder,
  type RadiologyCatalogItem,
  STANDARD_LAB_CATALOG,
  STANDARD_RADIOLOGY_CATALOG,
  useClinicianOrders,
} from "../composables/useClinicianOrders";

const props = withDefaults(
  defineProps<{
    encounterId: string | null;
    patientId: string | null;
    orders: ReturnType<typeof useClinicianOrders>;
    clinicalMode?: "active" | "awaiting_start" | "triage_pending" | "read_only" | "completed";
  }>(),
  {
    clinicalMode: "active",
  }
);

onMounted(() => {
  if (props.patientId || props.encounterId) {
    props.orders.fetchOrders(props.patientId ?? undefined, props.encounterId ?? undefined);
  }
});

watch(
  () => [props.patientId, props.encounterId],
  ([newPatientId, newEncounterId]) => {
    if (newPatientId || newEncounterId) {
      props.orders.fetchOrders(newPatientId ?? undefined, newEncounterId ?? undefined);
    }
  }
);

/**
 * This tab is lab and imaging only. Medication orders were being listed here as
 * well as in Prescriptions, so a prescription appeared twice under two
 * different headings and inflated the diagnostics count on the tab itself.
 */
const diagnosticOrders = computed<PlacedClinicalOrder[]>(() => props.orders.diagnosticOrders.value);

function orderStatusLabel(order: PlacedClinicalOrder): string {
  switch (diagnosticOrderStage(order)) {
    case "awaiting_payment":
      return t("clinician.order_stage_awaiting_payment", "Awaiting Payment");
    case "awaiting_collection":
      return order.isAuthorized
        ? t("clinician.order_stage_authorized", "Payment Verified · Awaiting sample")
        : t("clinician.order_stage_awaiting_collection", "Awaiting sample");
    case "in_progress":
      return t("clinician.order_stage_in_progress", "In progress");
    case "awaiting_release":
      return t("clinician.order_stage_awaiting_release", "Awaiting release");
    case "resulted":
      return t("clinician.order_stage_resulted", "Result ready");
    default:
      return t("clinician.order_stage_cancelled", "Cancelled");
  }
}

type OrderBadgeVariant = "success" | "info" | "critical" | "warning";

function orderBadgeVariant(order: PlacedClinicalOrder): OrderBadgeVariant {
  switch (diagnosticOrderStage(order)) {
    case "resulted":
      return "success";
    case "in_progress":
    case "awaiting_release":
      return "info";
    case "awaiting_collection":
      return "info";
    case "awaiting_payment":
      return "warning";
    case "cancelled":
      return "critical";
    default:
      return "warning";
  }
}

const { t } = useI18n({ useScope: "global" });

// Active sub-mode: lab vs imaging
const activeOrderMode = ref<"lab" | "imaging">("lab");

// Lab state
const labSearchQuery = ref("");
const selectedLabTest = ref<LabCatalogItem | null>(null);
const labPriority = ref<"routine" | "urgent" | "stat">("routine");
const labIndication = ref("");

// Radiology state
const radSearchQuery = ref("");
const selectedRadExam = ref<RadiologyCatalogItem | null>(null);
const radPriority = ref<"routine" | "urgent" | "stat">("routine");
const radIndication = ref("");

const filteredLabTests = computed(() => {
  const q = labSearchQuery.value.trim().toLowerCase();
  if (!q) return STANDARD_LAB_CATALOG;
  return STANDARD_LAB_CATALOG.filter(
    (item) =>
      item.code.toLowerCase().includes(q) ||
      item.name.toLowerCase().includes(q) ||
      item.department.toLowerCase().includes(q)
  );
});

const filteredRadExams = computed(() => {
  const q = radSearchQuery.value.trim().toLowerCase();
  if (!q) return STANDARD_RADIOLOGY_CATALOG;
  return STANDARD_RADIOLOGY_CATALOG.filter(
    (item) =>
      item.code.toLowerCase().includes(q) ||
      item.name.toLowerCase().includes(q) ||
      item.modality.toLowerCase().includes(q)
  );
});

// Diagnostics only. This summed medication too, so the figure a doctor read as
// "cost of tests ordered" silently included the prescription bill.
const totalPlacedDiagnosticCost = computed(() =>
  diagnosticOrders.value
    .filter((o) => o.status !== "cancelled")
    .reduce((acc, cur) => acc + (cur.price || 0), 0)
);

// Order Cancellation State
const orderToCancel = ref<PlacedClinicalOrder | null>(null);
const cancelReason = ref("Ordered in error");
const isCancelling = ref(false);

function openCancelDialog(order: PlacedClinicalOrder) {
  orderToCancel.value = order;
  cancelReason.value = "Ordered in error";
}

async function confirmCancelOrder() {
  if (!orderToCancel.value) return;
  isCancelling.value = true;
  try {
    await props.orders.cancelOrder(orderToCancel.value, cancelReason.value);
    orderToCancel.value = null;
  } finally {
    isCancelling.value = false;
  }
}

async function handlePlaceLabOrder() {
  if (!selectedLabTest.value || !props.encounterId || !props.patientId) return;
  const success = await props.orders.submitLabOrder(
    props.encounterId,
    props.patientId,
    selectedLabTest.value,
    labPriority.value,
    labIndication.value
  );
  if (success) {
    selectedLabTest.value = null;
    labIndication.value = "";
    labPriority.value = "routine";
  }
}

async function handlePlaceRadOrder() {
  if (!selectedRadExam.value || !props.encounterId || !props.patientId) return;
  const success = await props.orders.submitRadiologyOrder(
    props.encounterId,
    props.patientId,
    selectedRadExam.value,
    radPriority.value,
    radIndication.value
  );
  if (success) {
    selectedRadExam.value = null;
    radIndication.value = "";
    radPriority.value = "routine";
  }
}
</script>

<template>
  <div class="space-y-3 p-3.5">
    <!-- Clinical Gating / Operating Mode Banner -->
    <!--
      Queued for a doctor, nobody has started. Read-only until the consultation
      is actually opened — documenting or ordering here would create a record of
      a consultation that never happened.
    -->
    <div
      v-if="props.clinicalMode === 'awaiting_start'"
      class="rounded-md border border-primary/30 bg-primary/10 px-3 py-2 text-xs text-foreground flex items-center gap-2"
    >
      <div class="flex items-center gap-2">
        <Clock class="size-4 shrink-0 text-primary" />
        <span class="text-xs leading-tight">
          <strong class="font-semibold">{{ t("clinician.awaiting_start_title") }}:</strong>
          <span class="ml-1 text-muted-foreground">{{ t("clinician.awaiting_start_desc") }}</span>
        </span>
      </div>
    </div>

    <div
      v-else-if="props.clinicalMode === 'triage_pending'"
      class="rounded-md border border-amber-500/30 bg-amber-500/10 px-2.5 py-1.5 text-xs text-amber-900 dark:text-amber-200 flex items-center gap-2"
    >
      <Clock class="size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
      <span class="text-[11px] leading-tight">
        <strong class="font-semibold">{{ t("clinician.triage_pending_title") }}:</strong>
        <span class="text-amber-800/90 dark:text-amber-300/90 ml-1">{{ t("clinician.orders_gated_triage") }}</span>
      </span>
    </div>

    <div
      v-else-if="props.clinicalMode === 'read_only'"
      class="rounded-md border border-border/80 bg-muted/40 px-2.5 py-1.5 text-xs text-muted-foreground flex items-center gap-2"
    >
      <Eye class="size-3.5 shrink-0 text-primary" />
      <span class="text-[11px] leading-tight">
        <strong class="font-semibold text-foreground">{{ t("clinician.read_only_review_title") }}:</strong>
        <span class="ml-1">{{ t("clinician.orders_gated_not_checked_in") }}</span>
      </span>
    </div>

    <div
      v-else-if="props.clinicalMode === 'completed'"
      class="rounded-md border border-border bg-secondary/40 px-2.5 py-1.5 text-xs text-muted-foreground flex items-center gap-2"
    >
      <CheckCircle2 class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
      <span class="text-[11px] leading-tight">
        <strong class="font-semibold text-foreground">{{ t("clinician.encounter_completed_title") }}:</strong>
        <span class="ml-1">{{ t("clinician.encounter_completed_desc") }}</span>
      </span>
    </div>

    <!-- Top Switcher: Lab Tests vs Imaging Exams -->
    <div class="flex items-center justify-between gap-3 border-b border-border/60 pb-2 flex-wrap">
      <div class="inline-flex rounded-lg bg-muted/60 p-0.5 text-xs font-medium">
        <button
          type="button"
          class="flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition-all cursor-pointer"
          :class="
            activeOrderMode === 'lab'
              ? 'bg-card text-foreground font-bold shadow-xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="activeOrderMode = 'lab'"
        >
          <FlaskConical class="size-3.5 text-primary" />
          <span>{{ t("clinician.lab_panels") }}</span>
        </button>
        <button
          type="button"
          class="flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition-all cursor-pointer"
          :class="
            activeOrderMode === 'imaging'
              ? 'bg-card text-foreground font-bold shadow-xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="activeOrderMode = 'imaging'"
        >
          <Radio class="size-3.5 text-blue-600 dark:text-blue-400" />
          <span>{{ t("clinician.radiology_exams") }}</span>
        </button>
      </div>

      <div class="flex items-center gap-3 text-xs font-mono">
        <span v-if="totalPlacedDiagnosticCost > 0" class="text-muted-foreground">
          {{ t("clinician.estimated_total", "Est. Total:") }} <strong class="text-emerald-700 dark:text-emerald-400 font-bold">TZS {{ totalPlacedDiagnosticCost.toLocaleString() }}</strong>
        </span>
        <span class="text-muted-foreground">
          {{ diagnosticOrders.length }} {{ t("clinician.orders") }}
        </span>
      </div>
    </div>

    <!-- 1. Lab Order Form -->
    <div v-if="activeOrderMode === 'lab'" class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <!-- Left: Search & Select Lab Test -->
      <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2 flex flex-col justify-between">
        <div>
          <div class="flex items-center justify-between pb-1.5 border-b border-border/50">
            <span class="text-[11px] font-bold uppercase tracking-wider text-primary flex items-center gap-1.5">
              <FlaskConical class="size-3.5" />
              <span>{{ t("clinician.lab_panels") }}</span>
            </span>
          </div>

          <div class="space-y-2 pt-1.5 text-xs">
            <div class="relative">
              <Search class="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground" />
              <Input
                v-model="labSearchQuery"
                type="search"
                class="pl-8 h-8 text-xs font-medium disabled:opacity-60 disabled:cursor-not-allowed"
                :disabled="props.clinicalMode !== 'active'"
                :placeholder="t('clinician.search_lab_test')"
              />
            </div>

            <div class="max-h-96 overflow-y-auto space-y-1 pr-1">
              <div
                v-for="test in filteredLabTests"
                :key="test.id"
                class="flex items-center justify-between gap-2 p-2 rounded-md border text-xs transition-all"
                :class="[
                  selectedLabTest?.id === test.id
                    ? 'border-primary bg-primary/10 font-medium'
                    : 'border-border/70 hover:border-primary/40 hover:bg-secondary/60',
                  props.clinicalMode === 'active' ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'
                ]"
                @click="props.clinicalMode === 'active' && (selectedLabTest = test)"
              >
                <div class="min-w-0 flex-1">
                  <div class="flex items-center justify-between gap-1.5">
                    <div class="flex items-center gap-1.5 min-w-0 truncate">
                      <span class="font-mono font-bold text-primary text-[10.5px] bg-primary/10 px-1.5 py-0.2 rounded shrink-0">
                        {{ test.code }}
                      </span>
                      <span class="font-semibold text-foreground truncate text-[11.5px]">{{ test.name }}</span>
                    </div>
                    <span v-if="test.price" class="text-[11px] font-mono font-semibold text-emerald-700 dark:text-emerald-400 shrink-0">
                      TZS {{ test.price.toLocaleString() }}
                    </span>
                  </div>
                  <div class="text-[10px] text-muted-foreground mt-0.5">
                    {{ test.department }} · {{ t("clinician.sample_type") }}: {{ test.sampleType }}
                  </div>
                </div>
                <CheckCircle2
                  v-if="selectedLabTest?.id === test.id"
                  class="size-3.5 text-primary shrink-0 ml-1"
                />
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Right: Priority, Indication & Submit -->
      <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2 flex flex-col justify-between">
        <div>
          <div class="flex items-center justify-between pb-1.5 border-b border-border/50 gap-2">
            <span class="text-[11px] font-bold uppercase tracking-wider text-foreground truncate max-w-[240px]">
              {{ t("clinician.order_name") }}: {{ selectedLabTest ? selectedLabTest.name : t("clinician.select_test_prompt") }}
            </span>
            <span v-if="selectedLabTest?.price" class="text-[11px] font-mono font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-500/10 px-1.5 py-0.5 rounded shrink-0">
              {{ t("clinician.estimated_cost", "Est. Cost:") }} TZS {{ selectedLabTest.price.toLocaleString() }}
            </span>
          </div>

          <div class="space-y-2 pt-1.5 text-xs">
            <!-- Priority Selector -->
            <div class="space-y-1">
              <Label class="text-xs font-semibold text-foreground">{{ t("clinician.priority") }}</Label>
              <div class="grid grid-cols-3 gap-1.5">
                <button
                  type="button"
                  class="rounded-md border py-1.5 text-xs h-7.5 font-medium transition-all text-center disabled:opacity-50 disabled:cursor-not-allowed"
                  :class="[
                    labPriority === 'routine'
                      ? 'border-primary bg-primary/10 text-primary font-bold'
                      : 'border-border text-muted-foreground hover:bg-secondary',
                    props.clinicalMode === 'active' ? 'cursor-pointer' : 'cursor-not-allowed'
                  ]"
                  :disabled="props.clinicalMode !== 'active'"
                  @click="labPriority = 'routine'"
                >
                  {{ t("clinician.routine") }}
                </button>
                <button
                  type="button"
                  class="rounded-md border py-1.5 text-xs h-7.5 font-medium transition-all text-center disabled:opacity-50 disabled:cursor-not-allowed"
                  :class="[
                    labPriority === 'urgent'
                      ? 'border-warning bg-warning/10 text-warning font-bold'
                      : 'border-border text-muted-foreground hover:bg-secondary',
                    props.clinicalMode === 'active' ? 'cursor-pointer' : 'cursor-not-allowed'
                  ]"
                  :disabled="props.clinicalMode !== 'active'"
                  @click="labPriority = 'urgent'"
                >
                  {{ t("clinician.urgent") }}
                </button>
                <button
                  type="button"
                  class="rounded-md border py-1.5 text-xs h-7.5 font-medium transition-all text-center disabled:opacity-50 disabled:cursor-not-allowed"
                  :class="[
                    labPriority === 'stat'
                      ? 'border-critical bg-critical/10 text-critical font-bold'
                      : 'border-border text-muted-foreground hover:bg-secondary',
                    props.clinicalMode === 'active' ? 'cursor-pointer' : 'cursor-not-allowed'
                  ]"
                  :disabled="props.clinicalMode !== 'active'"
                  @click="labPriority = 'stat'"
                >
                  {{ t("clinician.priority_stat") }}
                </button>
              </div>
            </div>

            <!-- Clinical Indication -->
            <div class="space-y-1">
              <Label class="text-xs font-semibold text-foreground">
                {{ t("clinician.clinical_indication") }}
              </Label>
              <Textarea
                v-model="labIndication"
                rows="2"
                class="text-xs leading-relaxed disabled:opacity-60 disabled:cursor-not-allowed"
                :disabled="props.clinicalMode !== 'active'"
                :placeholder="t('clinician.indication_placeholder')"
              />
            </div>
          </div>
        </div>

        <div class="pt-2">
          <Button
            type="button"
            class="w-full gap-2 text-xs font-semibold h-8 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
            :disabled="props.clinicalMode !== 'active' || !selectedLabTest || orders.isPlacingOrder.value"
            @click="handlePlaceLabOrder"
          >
            <Send class="size-3.5" />
            <span>{{ orders.isPlacingOrder.value ? t("common.loading") : t("clinician.new_lab_order") }}</span>
          </Button>
        </div>
      </div>
    </div>

    <!-- 2. Radiology Order Form -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <!-- Left: Search & Select Imaging Exam -->
      <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2 flex flex-col justify-between">
        <div>
          <div class="flex items-center justify-between pb-1.5 border-b border-border/50">
            <span class="text-[11px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-400 flex items-center gap-1.5">
              <Radio class="size-3.5" />
              <span>{{ t("clinician.radiology_exams") }}</span>
            </span>
          </div>

          <div class="space-y-2 pt-1.5 text-xs">
            <div class="relative">
              <Search class="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground" />
              <Input
                v-model="radSearchQuery"
                type="search"
                class="pl-8 h-8 text-xs font-medium disabled:opacity-60 disabled:cursor-not-allowed"
                :disabled="props.clinicalMode !== 'active'"
                :placeholder="t('clinician.search_radiology_exam')"
              />
            </div>

            <div class="max-h-96 overflow-y-auto space-y-1 pr-1">
              <div
                v-for="exam in filteredRadExams"
                :key="exam.id"
                class="flex items-center justify-between gap-2 p-2 rounded-md border text-xs transition-all"
                :class="[
                  selectedRadExam?.id === exam.id
                    ? 'border-blue-600 bg-blue-500/10 font-medium'
                    : 'border-border/70 hover:border-blue-600/40 hover:bg-secondary/60',
                  props.clinicalMode === 'active' ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'
                ]"
                @click="props.clinicalMode === 'active' && (selectedRadExam = exam)"
              >
                <div class="min-w-0 flex-1">
                  <div class="flex items-center justify-between gap-1.5">
                    <div class="flex items-center gap-1.5 min-w-0 truncate">
                      <span class="font-mono font-bold text-blue-700 dark:text-blue-400 text-[10.5px] bg-blue-500/10 px-1.5 py-0.2 rounded shrink-0">
                        {{ exam.code }}
                      </span>
                      <span class="font-semibold text-foreground truncate text-[11.5px]">{{ exam.name }}</span>
                    </div>
                    <span v-if="exam.price" class="text-[11px] font-mono font-semibold text-emerald-700 dark:text-emerald-400 shrink-0">
                      TZS {{ exam.price.toLocaleString() }}
                    </span>
                  </div>
                  <div class="text-[10px] text-muted-foreground mt-0.5">
                    {{ t("clinician.modality") }}: {{ exam.modality }}
                  </div>
                </div>
                <CheckCircle2
                  v-if="selectedRadExam?.id === exam.id"
                  class="size-3.5 text-blue-600 dark:text-blue-400 shrink-0 ml-1"
                />
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Right: Priority, Indication & Submit -->
      <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2 flex flex-col justify-between">
        <div>
          <div class="flex items-center justify-between pb-1.5 border-b border-border/50 gap-2">
            <span class="text-[11px] font-bold uppercase tracking-wider text-foreground truncate max-w-[240px]">
              {{ t("clinician.order_name") }}: {{ selectedRadExam ? selectedRadExam.name : t("clinician.select_exam_prompt") }}
            </span>
            <span v-if="selectedRadExam?.price" class="text-[11px] font-mono font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-500/10 px-1.5 py-0.5 rounded shrink-0">
              {{ t("clinician.estimated_cost", "Est. Cost:") }} TZS {{ selectedRadExam.price.toLocaleString() }}
            </span>
          </div>

          <div class="space-y-2 pt-1.5 text-xs">
            <!-- Priority Selector -->
            <div class="space-y-1">
              <Label class="text-xs font-semibold text-foreground">{{ t("clinician.priority") }}</Label>
              <div class="grid grid-cols-3 gap-1.5">
                <button
                  type="button"
                  class="rounded-md border py-1.5 text-xs h-7.5 font-medium transition-all text-center disabled:opacity-50 disabled:cursor-not-allowed"
                  :class="[
                    radPriority === 'routine'
                      ? 'border-blue-600 bg-blue-500/10 text-blue-700 dark:text-blue-400 font-bold'
                      : 'border-border text-muted-foreground hover:bg-secondary',
                    props.clinicalMode === 'active' ? 'cursor-pointer' : 'cursor-not-allowed'
                  ]"
                  :disabled="props.clinicalMode !== 'active'"
                  @click="radPriority = 'routine'"
                >
                  {{ t("clinician.routine") }}
                </button>
                <button
                  type="button"
                  class="rounded-md border py-1.5 text-xs h-7.5 font-medium transition-all text-center disabled:opacity-50 disabled:cursor-not-allowed"
                  :class="[
                    radPriority === 'urgent'
                      ? 'border-warning bg-warning/10 text-warning font-bold'
                      : 'border-border text-muted-foreground hover:bg-secondary',
                    props.clinicalMode === 'active' ? 'cursor-pointer' : 'cursor-not-allowed'
                  ]"
                  :disabled="props.clinicalMode !== 'active'"
                  @click="radPriority = 'urgent'"
                >
                  {{ t("clinician.urgent") }}
                </button>
                <button
                  type="button"
                  class="rounded-md border py-1.5 text-xs h-7.5 font-medium transition-all text-center disabled:opacity-50 disabled:cursor-not-allowed"
                  :class="[
                    radPriority === 'stat'
                      ? 'border-critical bg-critical/10 text-critical font-bold'
                      : 'border-border text-muted-foreground hover:bg-secondary',
                    props.clinicalMode === 'active' ? 'cursor-pointer' : 'cursor-not-allowed'
                  ]"
                  :disabled="props.clinicalMode !== 'active'"
                  @click="radPriority = 'stat'"
                >
                  {{ t("clinician.priority_stat") }}
                </button>
              </div>
            </div>

            <!-- Clinical Indication -->
            <div class="space-y-1">
              <Label class="text-xs font-semibold text-foreground">
                {{ t("clinician.clinical_indication") }}
              </Label>
              <Textarea
                v-model="radIndication"
                rows="2"
                class="text-xs leading-relaxed disabled:opacity-60 disabled:cursor-not-allowed"
                :disabled="props.clinicalMode !== 'active'"
                :placeholder="t('clinician.indication_placeholder')"
              />
            </div>
          </div>
        </div>

        <div class="pt-2">
          <Button
            type="button"
            class="w-full gap-2 text-xs font-semibold bg-blue-600 hover:bg-blue-700 text-white h-8 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
            :disabled="props.clinicalMode !== 'active' || !selectedRadExam || orders.isPlacingOrder.value"
            @click="handlePlaceRadOrder"
          >
            <Send class="size-3.5" />
            <span>{{ orders.isPlacingOrder.value ? t("common.loading") : t("clinician.new_imaging_order") }}</span>
          </Button>
        </div>
      </div>
    </div>

    <!-- 3. Placed Orders List -->
    <div v-if="diagnosticOrders.length > 0" class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2">
      <div class="flex items-center justify-between pb-1.5 border-b border-border/50">
        <span class="text-[11px] font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
          <Clock class="size-3.5 text-muted-foreground" />
          <span>{{ t("clinician.orders") }} ({{ diagnosticOrders.length }})</span>
        </span>
        <span v-if="totalPlacedDiagnosticCost > 0" class="text-xs text-muted-foreground font-mono">
          {{ t("clinician.estimated_total", "Est. Total:") }} <strong class="text-emerald-700 dark:text-emerald-400 font-bold">TZS {{ totalPlacedDiagnosticCost.toLocaleString() }}</strong>
        </span>
      </div>

      <div>
        <ul class="divide-y divide-border/60">
          <li
            v-for="order in diagnosticOrders"
            :key="order.id"
            class="py-2 flex items-center justify-between gap-3 text-xs"
          >
            <div class="flex items-center gap-2 min-w-0">
              <Badge
                :variant="order.type === 'lab' ? 'default' : 'info'"
                class="uppercase text-[9px] px-1.5 py-0 font-mono shrink-0"
              >
                {{ order.type === 'lab' ? t('clinician.order_type_lab') : t('clinician.order_type_imaging') }}
              </Badge>
              <div>
                <span class="font-bold text-foreground text-[12px] block">{{ order.name }}</span>
                <span v-if="order.details" class="text-[10.5px] text-muted-foreground truncate block">
                  {{ order.details }}
                </span>
              </div>
            </div>

            <div class="flex items-center gap-2 shrink-0">
              <span v-if="order.price" class="text-[11px] font-mono font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-500/10 px-1.5 py-0.5 rounded">
                TZS {{ order.price.toLocaleString() }}
              </span>
              <Badge
                :variant="order.priority === 'stat' ? 'critical' : order.priority === 'urgent' ? 'warning' : 'secondary'"
                class="text-[9.5px] font-mono uppercase px-1.5 py-0"
              >
                {{ order.priority === 'stat' ? t('clinician.priority_stat') : order.priority === 'urgent' ? t('clinician.urgent') : t('clinician.routine') }}
              </Badge>
              <!--
                Reads the clinician-facing stage, not the bench status. A report
                that is written but not released used to render as "completed"
                here while the Results tab showed nothing — the doctor was told
                the work was done and then could not find it.
              -->
              <Badge
                :variant="orderBadgeVariant(order)"
                class="text-[9.5px] px-1.5 py-0"
              >
                {{ orderStatusLabel(order) }}
              </Badge>

              <!-- Cancel Button for Pending Orders -->
              <Button
                v-if="order.status === 'ordered' && props.clinicalMode === 'active'"
                type="button"
                variant="ghost"
                class="h-6 px-1.5 text-muted-foreground hover:text-critical hover:bg-critical/10 text-[11px] gap-1 cursor-pointer"
                @click="openCancelDialog(order)"
              >
                <XCircle class="size-3" />
                <span>{{ t("common.cancel", "Cancel") }}</span>
              </Button>
            </div>
          </li>
        </ul>
      </div>
    </div>

    <!-- Order Cancellation Dialog -->
    <Dialog :open="!!orderToCancel" @update:open="(val) => (!val ? (orderToCancel = null) : null)">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle class="flex items-center gap-2 text-critical text-sm">
            <AlertTriangle class="size-4" />
            <span>{{ t("clinician.cancel_order_title", "Cancel Diagnostic Order") }}</span>
          </DialogTitle>
          <DialogDescription class="text-xs text-muted-foreground pt-1">
            {{ t("clinician.cancel_order_desc", "Are you sure you want to cancel this order? This action transitions the order state to Cancelled and records an audit entry.") }}
          </DialogDescription>
        </DialogHeader>

        <div v-if="orderToCancel" class="space-y-3 py-2 text-xs">
          <div class="rounded-md bg-muted/50 p-2.5 space-y-1">
            <div class="font-semibold text-foreground text-xs">{{ orderToCancel.name }}</div>
            <div class="text-[11px] text-muted-foreground flex items-center gap-2">
              <span class="uppercase font-mono font-medium">{{ orderToCancel.type }}</span>
              <span v-if="orderToCancel.price" class="font-mono font-bold text-emerald-600 dark:text-emerald-400">
                TZS {{ orderToCancel.price.toLocaleString() }}
              </span>
            </div>
          </div>

          <div class="space-y-1">
            <Label class="text-xs font-semibold">{{ t("clinician.cancel_reason_label", "Reason for Cancellation") }}</Label>
            <select
              v-model="cancelReason"
              class="h-8 w-full rounded border border-border bg-background px-2 text-xs"
            >
              <option value="Ordered in error">Ordered in error</option>
              <option value="Patient condition changed">Patient condition changed</option>
              <option value="Duplicate order">Duplicate order</option>
              <option value="Patient declined / refused">Patient declined / refused</option>
              <option value="Clinical indication resolved">Clinical indication resolved</option>
            </select>
          </div>
        </div>

        <DialogFooter class="gap-2 sm:gap-0">
          <Button
            type="button"
            variant="outline"
            size="sm"
            class="text-xs cursor-pointer"
            @click="orderToCancel = null"
          >
            {{ t("common.back", "Keep Order") }}
          </Button>
          <Button
            type="button"
            variant="destructive"
            size="sm"
            class="text-xs gap-1.5 cursor-pointer"
            :disabled="isCancelling"
            @click="confirmCancelOrder"
          >
            <Loader2 v-if="isCancelling" class="size-3.5 animate-spin" />
            <XCircle v-else class="size-3.5" />
            <span>{{ isCancelling ? t("common.cancelling", "Cancelling...") : t("clinician.confirm_cancel", "Confirm Cancellation") }}</span>
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
