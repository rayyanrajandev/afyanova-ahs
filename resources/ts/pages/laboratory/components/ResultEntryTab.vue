/**
 * ResultEntryTab — Diagnostic Result Matrix & Clinical Range Evaluation (Volume 2.4 §7)
 * ======================================================================================
 * 2027 Modern Enterprise Hospital LIS Result Entry Station:
 * - High-Density Balanced Clinical Matrix Tables (Zero background clutter / No nested boxes)
 * - Symmetrical 2-Column Split Layout for Sectioned Tests (Physical + Microscopy vs Dipstick)
 * - Ultra-Fast Keyboard Data Entry (Direct vertical Tab flow)
 * - Compact Inputs, Sleek Dropdowns, and Instant Segmented Toggles
 * - Live Automated Reference Range & Critical Acuity Evaluation
 * - One-Click Section & Global Normal Baseline Autofill
 * - Panic Critical Alert Trigger with instant clinician read-back modal
 * - Full Internationalization (i18n) Support
 */

<script setup lang="ts">
import {
  Activity,
  AlertTriangle,
  Check,
  CheckCircle2,
  Clock,
  Eye,
  FileCheck,
  FlaskConical,
  Microscope,
  PhoneCall,
  Save,
  Sparkles,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import {
  labStageOf,
  missingParameters,
  type LabStage,
  type LabTestParameter,
  type LaboratoryOrder,
  type UseLaboratoryOrders,
} from "../composables/useLaboratoryOrders";
import CriticalAlertModal from "./CriticalAlertModal.vue";

const props = defineProps<{
  order: LaboratoryOrder;
  laboratory: UseLaboratoryOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const showCriticalModal = ref(false);

const stage = computed<LabStage>(() => labStageOf(props.order));
const isEditable = computed(() => stage.value === "in_analysis");

const missing = computed(() => missingParameters(props.order));
const totalParamCount = computed(() => props.order.parameters.length);
const filledParamCount = computed(
  () => totalParamCount.value - missing.value.length,
);
const completionPercentage = computed(() => {
  if (totalParamCount.value === 0) return 0;
  return Math.round((filledParamCount.value / totalParamCount.value) * 100);
});

const canSave = computed(() => isEditable.value && missing.value.length === 0);

interface ParameterGroup {
  id: string;
  label: string;
  icon: any;
  parameters: LabTestParameter[];
}

/**
 * Group parameters into section groups (e.g. Physical Examination, Dipstick, Microscopy).
 */
const sectionedGroups = computed<ParameterGroup[]>(() => {
  const map = new Map<string, LabTestParameter[]>();

  for (const param of props.order.parameters) {
    const rawSection = (param.section || "").trim();
    let sectionName = rawSection || "General Examination";

    if (!map.has(sectionName)) {
      map.set(sectionName, []);
    }
    map.get(sectionName)!.push(param);
  }

  const result: ParameterGroup[] = [];
  map.forEach((params, key) => {
    let icon = Activity;
    const lower = key.toLowerCase();
    if (lower.includes("physical") || lower.includes("macroscopic")) {
      icon = Eye;
    } else if (
      lower.includes("dipstick") ||
      lower.includes("chemical") ||
      lower.includes("reagent")
    ) {
      icon = FlaskConical;
    } else if (
      lower.includes("microscopy") ||
      lower.includes("microscopic") ||
      lower.includes("sediment") ||
      lower.includes("parasite")
    ) {
      icon = Microscope;
    }

    result.push({
      id: key.toLowerCase().replace(/[^a-z0-9]/g, "-"),
      label: key,
      icon,
      parameters: params,
    });
  });

  return result;
});

/**
 * Distribute groups into a balanced 2-column layout.
 * For Urinalysis: Column 1 gets Physical (2) + Microscopy (8) = 10 items.
 * Column 2 gets Dipstick (10 items). Both columns are 100% equal height!
 */
const columnLayout = computed(() => {
  const groups = sectionedGroups.value;
  if (groups.length <= 1) {
    return { isSplit: false, leftGroups: groups, rightGroups: [] };
  }

  const left: ParameterGroup[] = [];
  const right: ParameterGroup[] = [];
  let leftCount = 0;
  let rightCount = 0;

  // Specific clinical heuristic: keep Physical + Microscopy on one side, Dipstick on the other
  const dipstickGroup = groups.find((g) =>
    g.label.toLowerCase().includes("dipstick") || g.label.toLowerCase().includes("chemical"),
  );

  if (dipstickGroup && groups.length >= 2) {
    right.push(dipstickGroup);
    rightCount += dipstickGroup.parameters.length;

    for (const g of groups) {
      if (g !== dipstickGroup) {
        left.push(g);
        leftCount += g.parameters.length;
      }
    }
  } else {
    // General greedy balance for any other multi-section test (e.g. Stool, Panels)
    for (const g of groups) {
      if (leftCount <= rightCount) {
        left.push(g);
        leftCount += g.parameters.length;
      } else {
        right.push(g);
        rightCount += g.parameters.length;
      }
    }
  }

  return { isSplit: true, leftGroups: left, rightGroups: right };
});

function selectOptionsFor(param: LabTestParameter): string[] {
  if (param.options && param.options.length > 0) return param.options;
  if (param.fieldType === "positive-negative") {
    return ["Negative", "Positive"];
  }
  return [];
}

function isSelectField(param: LabTestParameter): boolean {
  return (
    (param.fieldType === "select" || param.fieldType === "positive-negative") &&
    selectOptionsFor(param).length > 0
  );
}

function handleValueChange(param: LabTestParameter) {
  param.flag = props.laboratory.evaluateParameterFlag(param);
}

function setParamQuickValue(param: LabTestParameter, val: string) {
  if (!isEditable.value) return;
  param.value = val;
  handleValueChange(param);
}

// Check if any entered parameter is in panic critical state
const hasCriticalValue = computed(() => {
  return props.order.parameters.some(
    (p) => p.flag === "critical_low" || p.flag === "critical_high",
  );
});

const criticalParameters = computed(() => {
  return props.order.parameters.filter(
    (p) => p.flag === "critical_low" || p.flag === "critical_high",
  );
});

function handleFillNormal() {
  props.laboratory.fillNormalDefaults(props.order.id);
}

function handleFillSectionNormal(group: ParameterGroup) {
  if (!isEditable.value) return;
  for (const p of group.parameters) {
    const key = (p.key || "").toLowerCase();
    if (key === "color") {
      p.value = p.options?.includes("Yellow") ? "Yellow" : "Pale Yellow";
    } else if (key === "appearance") {
      p.value = "Clear";
    } else if (key === "specific_gravity" || key === "sg") {
      p.value = "1.015";
    } else if (key === "ph") {
      p.value = p.fieldType === "number" ? 6.0 : "6.0";
    } else if (key === "wbc" || key === "pus_cells") {
      p.value = "0–2/HPF";
    } else if (key === "rbc") {
      p.value = "0–1/HPF";
    } else if (key === "epithelial_cells") {
      p.value = "Few";
    } else if (p.options?.includes("None Seen")) {
      p.value = "None Seen";
    } else if (p.options?.includes("Negative")) {
      p.value = "Negative";
    } else if (p.options?.includes("Non-Reactive")) {
      p.value = "Non-Reactive";
    } else if (p.options?.includes("Normal")) {
      p.value = "Normal";
    } else if (
      p.unit === "result" ||
      p.fieldType === "positive-negative" ||
      p.referenceRange?.includes("Negative") ||
      p.referenceRange?.includes("Non-Reactive")
    ) {
      p.value = p.referenceRange?.includes("Non-Reactive")
        ? "Non-Reactive"
        : "Negative";
    } else if (p.minNormal !== undefined && p.maxNormal !== undefined) {
      p.value = Number(((p.minNormal + p.maxNormal) / 2).toFixed(1));
    } else if (p.maxNormal !== undefined) {
      p.value = Number((p.maxNormal * 0.7).toFixed(1));
    } else if (p.minNormal !== undefined) {
      p.value = Number((p.minNormal * 1.2).toFixed(1));
    }
    p.flag = props.laboratory.evaluateParameterFlag(p);
  }
}

function handleSaveResults() {
  void props.laboratory.saveResults(props.order.id);
}
</script>

<template>
  <div class="space-y-2.5 p-3 w-full max-w-7xl mx-auto">
    <!-- Panic Critical Value Banner (Safety Alert P1) -->
    <div
      v-if="hasCriticalValue"
      class="rounded-lg bg-rose-500/15 border border-rose-500/40 px-3 py-2 text-xs text-rose-950 dark:text-rose-100 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 shadow-xs animate-pulse"
    >
      <div class="flex items-center gap-2 min-w-0">
        <div
          class="flex size-6 items-center justify-center rounded-full bg-rose-600 text-white shrink-0 shadow-xs"
        >
          <AlertTriangle class="size-3.5" />
        </div>
        <div class="min-w-0">
          <h4 class="text-xs font-bold uppercase tracking-wider text-rose-700 dark:text-rose-300 leading-tight">
            {{ t("laboratory.critical_alert_title", "Critical Panic Value Alert") }}
          </h4>
          <p class="text-[11px] text-rose-900 dark:text-rose-200 truncate leading-tight">
            <span v-for="(crit, idx) in criticalParameters" :key="crit.key">
              <strong>{{ crit.name }}: {{ crit.value }} {{ crit.unit }}</strong>
              (Ref: {{ crit.referenceRange }}){{ idx < criticalParameters.length - 1 ? " · " : "" }}
            </span>
          </p>
        </div>
      </div>

      <Button
        size="sm"
        class="h-6.5 text-xs font-bold gap-1 px-2.5 bg-rose-600 hover:bg-rose-700 text-white cursor-pointer shadow-xs shrink-0 border-0"
        @click="showCriticalModal = true"
      >
        <PhoneCall class="size-3" />
        <span>{{ t("laboratory.log_clinician_call", "Log Clinician Call") }}</span>
      </Button>
    </div>

    <!-- Master Unified Laboratory Workbench Container -->
    <div class="rounded-lg border border-border bg-surface shadow-2xs overflow-hidden">
      <!-- Workbench Sub-Header Bar -->
      <div class="px-3.5 py-2 border-b border-border/70 flex flex-col sm:flex-row sm:items-center justify-between gap-2 bg-surface">
        <div class="flex items-center gap-2.5 min-w-0">
          <div class="flex size-6.5 items-center justify-center rounded-md bg-primary/10 text-primary shrink-0">
            <Activity class="size-3.5" aria-hidden="true" />
          </div>
          <div class="flex items-center gap-2 flex-wrap min-w-0">
            <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
              {{ order.testName }}
            </h3>
            <span class="text-[9.5px] font-mono px-1.5 py-0.2 rounded bg-muted text-muted-foreground uppercase font-semibold">
              {{ order.sampleType || "Specimen" }}
            </span>
            <span
              class="text-[9.5px] font-mono font-bold px-1.5 py-0.2 rounded"
              :class="
                completionPercentage === 100
                  ? 'text-emerald-700 dark:text-emerald-300 bg-emerald-500/15'
                  : 'text-amber-700 dark:text-amber-300 bg-amber-500/15'
              "
            >
              {{ completionPercentage }}% ({{ filledParamCount }}/{{ totalParamCount }})
            </span>
          </div>
        </div>

        <!-- Global Fill Normal Button -->
        <div class="flex items-center gap-2 shrink-0">
          <Button
            v-if="isEditable"
            variant="outline"
            size="sm"
            class="h-6.5 text-xs px-2.5 text-primary border-primary/40 hover:bg-primary/10 cursor-pointer gap-1.5 shadow-2xs font-semibold"
            @click="handleFillNormal"
          >
            <Sparkles class="size-3" />
            <span>{{ t("laboratory.fill_normal", "Fill Normal Defaults") }}</span>
          </Button>
        </div>
      </div>

      <!-- High-Density Clinical Matrix Tables (2-Column Balanced Grid) -->
      <div class="p-3">
        <div
          v-if="columnLayout.isSplit"
          class="grid grid-cols-1 lg:grid-cols-2 gap-3.5 items-start"
        >
          <!-- Left Column Groups (e.g. Physical + Microscopy) -->
          <div class="space-y-3.5">
            <div
              v-for="group in columnLayout.leftGroups"
              :key="group.id"
              class="rounded-md border border-border/80 bg-surface overflow-hidden"
            >
              <!-- Section Header -->
              <div class="flex items-center justify-between px-3 py-1.5 bg-muted/25 border-b border-border/60">
                <div class="flex items-center gap-1.5 font-bold text-xs uppercase tracking-wider text-foreground">
                  <component :is="group.icon" class="size-3.5 text-primary" />
                  <span>{{ group.label }}</span>
                  <span class="text-[9.5px] font-mono text-muted-foreground font-normal">
                    ({{ group.parameters.length }})
                  </span>
                </div>
                <button
                  v-if="isEditable"
                  type="button"
                  class="text-[10.5px] text-primary hover:underline flex items-center gap-1 cursor-pointer font-medium"
                  :title="t('laboratory.set_section_normal', 'Set section to normal')"
                  @click="handleFillSectionNormal(group)"
                >
                  <Sparkles class="size-3" />
                  <span>{{ t("laboratory.set_section_normal", "Fill Normal") }}</span>
                </button>
              </div>

              <!-- Matrix Table -->
              <table class="w-full text-left text-xs table-fixed">
                <thead class="border-b border-border/50 text-[10px] font-semibold text-muted-foreground uppercase bg-muted/10">
                  <tr>
                    <th class="py-1 px-2.5 w-[36%]">{{ t("laboratory.th_parameter", "Parameter") }}</th>
                    <th class="py-1 px-2 w-[34%]">{{ t("laboratory.th_value", "Result") }}</th>
                    <th class="py-1 px-2 w-[18%]">{{ t("laboratory.th_reference", "Reference") }}</th>
                    <th class="py-1 px-2 w-[12%] text-right pr-2.5">{{ t("laboratory.th_flag", "Flag") }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-border/40">
                  <tr
                    v-for="param in group.parameters"
                    :key="param.key"
                    class="hover:bg-muted/20 transition-colors"
                  >
                    <td class="py-1 px-2.5 font-medium text-foreground text-[11px] truncate" :title="param.name">
                      {{ param.name }}
                      <span v-if="param.previousValue" class="text-[9px] text-muted-foreground font-mono font-normal">
                        ({{ param.previousValue }})
                      </span>
                    </td>
                    <td class="py-1 px-2">
                      <!-- Positive / Negative Toggle -->
                      <div
                        v-if="param.fieldType === 'positive-negative'"
                        class="grid grid-cols-2 gap-1 bg-muted/50 p-0.5 rounded"
                      >
                        <button
                          type="button"
                          :disabled="!isEditable"
                          class="h-6 text-[10.5px] font-mono font-bold rounded transition-colors cursor-pointer"
                          :class="
                            param.value === 'Negative'
                              ? 'bg-surface text-emerald-700 dark:text-emerald-300 shadow-2xs font-bold'
                              : 'text-muted-foreground hover:text-foreground'
                          "
                          @click="setParamQuickValue(param, 'Negative')"
                        >
                          - Neg
                        </button>
                        <button
                          type="button"
                          :disabled="!isEditable"
                          class="h-6 text-[10.5px] font-mono font-bold rounded transition-colors cursor-pointer"
                          :class="
                            param.value === 'Positive'
                              ? 'bg-amber-500 text-white shadow-2xs font-extrabold'
                              : 'text-muted-foreground hover:text-foreground'
                          "
                          @click="setParamQuickValue(param, 'Positive')"
                        >
                          + Pos
                        </button>
                      </div>

                      <!-- Select Dropdown -->
                      <Select
                        v-else-if="isSelectField(param)"
                        :model-value="param.value ? String(param.value) : undefined"
                        :disabled="!isEditable"
                        @update:model-value="
                          (v) => {
                            param.value = typeof v === 'string' ? v : null;
                            handleValueChange(param);
                          }
                        "
                      >
                        <SelectTrigger
                          class="h-6.5 text-xs font-mono font-semibold w-full bg-background"
                          :class="{
                            'border-rose-500 text-rose-600 bg-rose-500/10':
                              param.flag === 'critical_low' || param.flag === 'critical_high',
                            'border-amber-500 text-amber-600 bg-amber-500/10':
                              param.flag === 'abnormal',
                          }"
                        >
                          <SelectValue :placeholder="param.placeholder || '—'" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem
                            v-for="opt in selectOptionsFor(param)"
                            :key="opt"
                            :value="opt"
                          >
                            {{ opt }}
                          </SelectItem>
                        </SelectContent>
                      </Select>

                      <!-- Text / Number Input -->
                      <Input
                        v-else
                        v-model="param.value"
                        :type="param.fieldType === 'number' ? 'number' : 'text'"
                        :placeholder="param.placeholder || '—'"
                        class="h-6.5 text-xs font-mono font-bold bg-background"
                        :class="{
                          'border-rose-500 text-rose-600 bg-rose-500/10':
                            param.flag === 'critical_low' || param.flag === 'critical_high',
                          'border-amber-500 text-amber-600 bg-amber-500/10':
                            param.flag === 'abnormal',
                        }"
                        :disabled="!isEditable"
                        @input="handleValueChange(param)"
                      />
                    </td>
                    <td class="py-1 px-2 text-[10px] font-mono text-muted-foreground truncate" :title="param.referenceRange">
                      {{ param.referenceRange || 'Normal' }}
                      <span v-if="param.unit" class="text-foreground/70">{{ param.unit }}</span>
                    </td>
                    <td class="py-1 px-2 text-right pr-2.5">
                      <span
                        v-if="param.flag === 'critical_high' || param.flag === 'critical_low'"
                        class="text-[8px] font-mono font-extrabold px-1 py-0.2 rounded bg-rose-600 text-white animate-pulse"
                      >
                        CRIT
                      </span>
                      <span
                        v-else-if="param.flag === 'abnormal'"
                        class="text-[8.5px] font-mono font-bold px-1 py-0.2 rounded bg-amber-500/20 text-amber-700 dark:text-amber-300"
                      >
                        ABN
                      </span>
                      <span
                        v-else
                        class="text-[8.5px] font-mono text-muted-foreground/70"
                      >
                        NORM
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Right Column Groups (e.g. Dipstick) -->
          <div class="space-y-3.5">
            <div
              v-for="group in columnLayout.rightGroups"
              :key="group.id"
              class="rounded-md border border-border/80 bg-surface overflow-hidden"
            >
              <!-- Section Header -->
              <div class="flex items-center justify-between px-3 py-1.5 bg-muted/25 border-b border-border/60">
                <div class="flex items-center gap-1.5 font-bold text-xs uppercase tracking-wider text-foreground">
                  <component :is="group.icon" class="size-3.5 text-primary" />
                  <span>{{ group.label }}</span>
                  <span class="text-[9.5px] font-mono text-muted-foreground font-normal">
                    ({{ group.parameters.length }})
                  </span>
                </div>
                <button
                  v-if="isEditable"
                  type="button"
                  class="text-[10.5px] text-primary hover:underline flex items-center gap-1 cursor-pointer font-medium"
                  :title="t('laboratory.set_section_normal', 'Set section to normal')"
                  @click="handleFillSectionNormal(group)"
                >
                  <Sparkles class="size-3" />
                  <span>{{ t("laboratory.set_section_normal", "Fill Normal") }}</span>
                </button>
              </div>

              <!-- Matrix Table -->
              <table class="w-full text-left text-xs table-fixed">
                <thead class="border-b border-border/50 text-[10px] font-semibold text-muted-foreground uppercase bg-muted/10">
                  <tr>
                    <th class="py-1 px-2.5 w-[36%]">{{ t("laboratory.th_parameter", "Parameter") }}</th>
                    <th class="py-1 px-2 w-[34%]">{{ t("laboratory.th_value", "Result") }}</th>
                    <th class="py-1 px-2 w-[18%]">{{ t("laboratory.th_reference", "Reference") }}</th>
                    <th class="py-1 px-2 w-[12%] text-right pr-2.5">{{ t("laboratory.th_flag", "Flag") }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-border/40">
                  <tr
                    v-for="param in group.parameters"
                    :key="param.key"
                    class="hover:bg-muted/20 transition-colors"
                  >
                    <td class="py-1 px-2.5 font-medium text-foreground text-[11px] truncate" :title="param.name">
                      {{ param.name }}
                      <span v-if="param.previousValue" class="text-[9px] text-muted-foreground font-mono font-normal">
                        ({{ param.previousValue }})
                      </span>
                    </td>
                    <td class="py-1 px-2">
                      <!-- Positive / Negative Toggle -->
                      <div
                        v-if="param.fieldType === 'positive-negative'"
                        class="grid grid-cols-2 gap-1 bg-muted/50 p-0.5 rounded"
                      >
                        <button
                          type="button"
                          :disabled="!isEditable"
                          class="h-6 text-[10.5px] font-mono font-bold rounded transition-colors cursor-pointer"
                          :class="
                            param.value === 'Negative'
                              ? 'bg-surface text-emerald-700 dark:text-emerald-300 shadow-2xs font-bold'
                              : 'text-muted-foreground hover:text-foreground'
                          "
                          @click="setParamQuickValue(param, 'Negative')"
                        >
                          - Neg
                        </button>
                        <button
                          type="button"
                          :disabled="!isEditable"
                          class="h-6 text-[10.5px] font-mono font-bold rounded transition-colors cursor-pointer"
                          :class="
                            param.value === 'Positive'
                              ? 'bg-amber-500 text-white shadow-2xs font-extrabold'
                              : 'text-muted-foreground hover:text-foreground'
                          "
                          @click="setParamQuickValue(param, 'Positive')"
                        >
                          + Pos
                        </button>
                      </div>

                      <!-- Select Dropdown -->
                      <Select
                        v-else-if="isSelectField(param)"
                        :model-value="param.value ? String(param.value) : undefined"
                        :disabled="!isEditable"
                        @update:model-value="
                          (v) => {
                            param.value = typeof v === 'string' ? v : null;
                            handleValueChange(param);
                          }
                        "
                      >
                        <SelectTrigger
                          class="h-6.5 text-xs font-mono font-semibold w-full bg-background"
                          :class="{
                            'border-rose-500 text-rose-600 bg-rose-500/10':
                              param.flag === 'critical_low' || param.flag === 'critical_high',
                            'border-amber-500 text-amber-600 bg-amber-500/10':
                              param.flag === 'abnormal',
                          }"
                        >
                          <SelectValue :placeholder="param.placeholder || '—'" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem
                            v-for="opt in selectOptionsFor(param)"
                            :key="opt"
                            :value="opt"
                          >
                            {{ opt }}
                          </SelectItem>
                        </SelectContent>
                      </Select>

                      <!-- Text / Number Input -->
                      <Input
                        v-else
                        v-model="param.value"
                        :type="param.fieldType === 'number' ? 'number' : 'text'"
                        :placeholder="param.placeholder || '—'"
                        class="h-6.5 text-xs font-mono font-bold bg-background"
                        :class="{
                          'border-rose-500 text-rose-600 bg-rose-500/10':
                            param.flag === 'critical_low' || param.flag === 'critical_high',
                          'border-amber-500 text-amber-600 bg-amber-500/10':
                            param.flag === 'abnormal',
                        }"
                        :disabled="!isEditable"
                        @input="handleValueChange(param)"
                      />
                    </td>
                    <td class="py-1 px-2 text-[10px] font-mono text-muted-foreground truncate" :title="param.referenceRange">
                      {{ param.referenceRange || 'Normal' }}
                      <span v-if="param.unit" class="text-foreground/70">{{ param.unit }}</span>
                    </td>
                    <td class="py-1 px-2 text-right pr-2.5">
                      <span
                        v-if="param.flag === 'critical_high' || param.flag === 'critical_low'"
                        class="text-[8px] font-mono font-extrabold px-1 py-0.2 rounded bg-rose-600 text-white animate-pulse"
                      >
                        CRIT
                      </span>
                      <span
                        v-else-if="param.flag === 'abnormal'"
                        class="text-[8.5px] font-mono font-bold px-1 py-0.2 rounded bg-amber-500/20 text-amber-700 dark:text-amber-300"
                      >
                        ABN
                      </span>
                      <span
                        v-else
                        class="text-[8.5px] font-mono text-muted-foreground/70"
                      >
                        NORM
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Single Section Full Width / Balanced View -->
        <div v-else class="space-y-3">
          <div
            v-for="group in columnLayout.leftGroups"
            :key="group.id"
            class="rounded-md border border-border/80 bg-surface overflow-hidden"
          >
            <div class="flex items-center justify-between px-3 py-1.5 bg-muted/25 border-b border-border/60">
              <div class="flex items-center gap-1.5 font-bold text-xs uppercase tracking-wider text-foreground">
                <component :is="group.icon" class="size-3.5 text-primary" />
                <span>{{ group.label }}</span>
                <span class="text-[9.5px] font-mono text-muted-foreground font-normal">
                  ({{ group.parameters.length }})
                </span>
              </div>
              <button
                v-if="isEditable"
                type="button"
                class="text-[10.5px] text-primary hover:underline flex items-center gap-1 cursor-pointer font-medium"
                :title="t('laboratory.set_section_normal', 'Set section to normal')"
                @click="handleFillSectionNormal(group)"
              >
                <Sparkles class="size-3" />
                <span>{{ t("laboratory.set_section_normal", "Fill Normal") }}</span>
              </button>
            </div>

            <table class="w-full text-left text-xs table-fixed">
              <thead class="border-b border-border/50 text-[10px] font-semibold text-muted-foreground uppercase bg-muted/10">
                <tr>
                  <th class="py-1 px-3 w-[34%]">{{ t("laboratory.th_parameter", "Parameter") }}</th>
                  <th class="py-1 px-2.5 w-[30%]">{{ t("laboratory.th_value", "Result") }}</th>
                  <th class="py-1 px-2.5 w-[22%]">{{ t("laboratory.th_reference", "Reference") }}</th>
                  <th class="py-1 px-3 w-[14%] text-right pr-3">{{ t("laboratory.th_flag", "Flag") }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/40">
                <tr
                  v-for="param in group.parameters"
                  :key="param.key"
                  class="hover:bg-muted/20 transition-colors"
                >
                  <td class="py-1.5 px-3 font-medium text-foreground text-[11.5px]">
                    {{ param.name }}
                    <span v-if="param.previousValue" class="text-[9px] text-muted-foreground font-mono font-normal">
                      ({{ param.previousValue }})
                    </span>
                  </td>
                  <td class="py-1.5 px-2.5">
                    <Select
                      v-if="isSelectField(param)"
                      :model-value="param.value ? String(param.value) : undefined"
                      :disabled="!isEditable"
                      @update:model-value="
                        (v) => {
                          param.value = typeof v === 'string' ? v : null;
                          handleValueChange(param);
                        }
                      "
                    >
                      <SelectTrigger
                        class="h-6.5 text-xs font-mono font-semibold w-full bg-background"
                        :class="{
                          'border-rose-500 text-rose-600 bg-rose-500/10':
                            param.flag === 'critical_low' || param.flag === 'critical_high',
                          'border-amber-500 text-amber-600 bg-amber-500/10':
                            param.flag === 'abnormal',
                        }"
                      >
                        <SelectValue :placeholder="param.placeholder || '—'" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem
                          v-for="opt in selectOptionsFor(param)"
                          :key="opt"
                          :value="opt"
                        >
                          {{ opt }}
                        </SelectItem>
                      </SelectContent>
                    </Select>

                    <Input
                      v-else
                      v-model="param.value"
                      :type="param.fieldType === 'number' ? 'number' : 'text'"
                      :placeholder="param.placeholder || '—'"
                      class="h-6.5 text-xs font-mono font-bold bg-background"
                      :class="{
                        'border-rose-500 text-rose-600 bg-rose-500/10':
                          param.flag === 'critical_low' || param.flag === 'critical_high',
                        'border-amber-500 text-amber-600 bg-amber-500/10':
                          param.flag === 'abnormal',
                      }"
                      :disabled="!isEditable"
                      @input="handleValueChange(param)"
                    />
                  </td>
                  <td class="py-1.5 px-2.5 text-[10.5px] font-mono text-muted-foreground">
                    {{ param.referenceRange || 'Normal' }}
                    <span v-if="param.unit" class="text-foreground/70">{{ param.unit }}</span>
                  </td>
                  <td class="py-1.5 px-3 text-right pr-3">
                    <span
                      v-if="param.flag === 'critical_high' || param.flag === 'critical_low'"
                      class="text-[8px] font-mono font-extrabold px-1.5 py-0.5 rounded bg-rose-600 text-white animate-pulse"
                    >
                      CRIT
                    </span>
                    <span
                      v-else-if="param.flag === 'abnormal'"
                      class="text-[8.5px] font-mono font-bold px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-700 dark:text-amber-300"
                    >
                      ABN
                    </span>
                    <span
                      v-else
                      class="text-[8.5px] font-mono text-muted-foreground/70"
                    >
                      NORM
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Integrated Notes & Clinical Interpretation -->
        <div class="pt-3 border-t border-border/50 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs mt-3">
          <div class="space-y-1">
            <Label class="text-xs font-semibold text-foreground">
              {{ t("laboratory.technician_remarks", "Technologist Analyzer Remarks / Equipment") }}
            </Label>
            <Textarea
              v-model="order.technicianNotes"
              rows="2"
              class="text-xs resize-none bg-background"
              :placeholder="
                t(
                  'laboratory.technician_remarks_placeholder',
                  'e.g. Analyzed on Sysmex XN-550 / Beckman AU480; calibrated today...',
                )
              "
              :disabled="!isEditable"
            />
          </div>

          <div class="space-y-1">
            <Label class="text-xs font-semibold text-foreground">
              {{ t("laboratory.clinical_interpretation", "Clinical Interpretation & Recommendation") }}
            </Label>
            <Textarea
              v-model="order.interpretation"
              rows="2"
              class="text-xs resize-none bg-background"
              :placeholder="
                t(
                  'laboratory.clinical_interpretation_placeholder',
                  'e.g. Findings consistent with microcytic hypochromic anemia...',
                )
              "
              :disabled="!isEditable"
            />
          </div>
        </div>
      </div>

      <!-- Integrated Bottom Action Footer -->
      <div class="flex flex-wrap items-center justify-between gap-3 px-3.5 py-2.5 bg-muted/20 border-t border-border/60">
        <div class="flex items-center gap-2 text-xs text-muted-foreground">
          <Clock class="size-3.5 text-primary shrink-0" />
          <span
            v-if="isEditable && missing.length > 0"
            class="text-amber-700 dark:text-amber-300 font-medium"
          >
            {{ t("laboratory.awaiting_values", { count: missing.length }) }}
          </span>
          <span v-else-if="isEditable" class="text-emerald-700 dark:text-emerald-300 font-semibold">
            {{ t("laboratory.all_values_entered", "All parameters entered — ready to save.") }}
          </span>
          <span
            v-else-if="
              stage === 'awaiting_specimen' || stage === 'ready_for_analysis'
            "
          >
            {{ t("laboratory.entry_locked_pre", "Result entry opens once analysis has started.") }}
          </span>
          <span v-else-if="stage === 'rejected'">
            {{ t("laboratory.entry_locked_rejected", "This specimen was rejected — no results can be entered.") }}
          </span>
          <span v-else>
            {{ t("laboratory.entry_locked_saved", "Results are saved and can no longer be edited here.") }}
          </span>
        </div>

        <div class="flex items-center gap-2">
          <Button
            v-if="isEditable"
            size="sm"
            class="h-7.5 text-xs font-semibold gap-1.5 px-4 shadow-xs"
            :class="canSave ? 'cursor-pointer' : 'cursor-not-allowed'"
            :disabled="!canSave || laboratory.isSavingResults.value"
            :title="
              canSave
                ? ''
                : t('laboratory.save_blocked', 'Fill every parameter first')
            "
            @click="handleSaveResults"
          >
            <Save class="size-3.5" />
            <span>
              {{
                laboratory.isSavingResults.value
                  ? t("laboratory.saving_results", "Saving...")
                  : t("laboratory.save_results", "Save Results")
              }}
            </span>
          </Button>

          <span
            v-else-if="stage === 'awaiting_release'"
            class="inline-flex items-center gap-1.5 text-sky-600 font-bold text-xs"
          >
            <FileCheck class="size-4" />
            {{ t("laboratory.draft_saved_go_release", "Draft saved — release it from the Verification tab") }}
          </span>

          <span
            v-else-if="stage === 'released'"
            class="inline-flex items-center gap-1.5 text-emerald-600 font-bold text-xs"
          >
            <CheckCircle2 class="size-4" />
            {{ t("laboratory.verified_and_published", "Released to patient chart") }}
          </span>
        </div>
      </div>
    </div>

    <!-- Critical Alert Modal -->
    <CriticalAlertModal
      v-if="showCriticalModal"
      :order="order"
      @close="showCriticalModal = false"
      @logged="
        (clinician) => {
          laboratory.logCriticalNotification(order.id, clinician);
          showCriticalModal = false;
        }
      "
    />
  </div>
</template>


