/**
 * VitalsHistoryTab — Patient Vitals & Clinical Acuity (Volume 2.2 §6.1 / Volume 2.3 §8)
 * ====================================================================================
 * Displays nurse-recorded baseline vitals, NEWS2 score, vital trends, and historical metrics.
 * Renders an empty state when no vitals have been recorded for the patient.
 */

<script setup lang="ts">
import {
  Activity,
  AlertTriangle,
  Clock,
  Heart,
  Loader2,
  Scale,
  Smile,
  Thermometer,
  Wind,
} from "lucide-vue-next";
import { computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import EmptyState from "@/components/common/EmptyState.vue";
import { Badge } from "@/components/ui/badge";
import { formatClinicalDate } from "@/pages/reception/receptionFormatters";
import type { Patient } from "@/stores/patientStore";
import { useVitalsStore } from "@/stores/vitalsStore";

const props = defineProps<{
  patient: Patient;
  vitals?: any;
}>();

const { t } = useI18n({ useScope: "global" });
const vitalsStore = useVitalsStore();

// Automatically load the latest vital set for the patient if not passed directly in props
watch(
  () => props.patient?.id,
  (patientId) => {
    if (patientId) {
      void vitalsStore.fetchLatest(patientId);
    }
  },
  { immediate: true },
);

// Active vital reading (from encounter props or vitals store)
const activeVitalRecord = computed(() => {
  if (props.vitals && typeof props.vitals === "object" && Object.keys(props.vitals).length > 0) {
    return props.vitals;
  }
  return vitalsStore.latest;
});

// Check if any vital sign metric is actually recorded
const hasVitals = computed(() => {
  const v = activeVitalRecord.value;
  if (!v) return false;
  return (
    v.temperatureC != null ||
    v.temperature != null ||
    v.heartRateBpm != null ||
    v.heartRate != null ||
    v.pulse != null ||
    v.systolicBpMmhg != null ||
    v.systolic != null ||
    v.diastolicBpMmhg != null ||
    v.diastolic != null ||
    v.bloodPressure != null ||
    v.oxygenSaturationPct != null ||
    v.spo2 != null ||
    v.oxygenSaturation != null ||
    v.respiratoryRateBpm != null ||
    v.respiratoryRate != null ||
    v.weightKg != null ||
    v.weight != null ||
    v.heightCm != null ||
    v.height != null ||
    v.painScore != null
  );
});

// Blood Pressure
const sbp = computed<number | null>(() => {
  const v = activeVitalRecord.value;
  return v?.systolicBpMmhg ?? v?.systolic ?? null;
});

const dbp = computed<number | null>(() => {
  const v = activeVitalRecord.value;
  return v?.diastolicBpMmhg ?? v?.diastolic ?? null;
});

const bp = computed<string>(() => {
  if (sbp.value != null && dbp.value != null) {
    return `${sbp.value}/${dbp.value}`;
  }
  if (activeVitalRecord.value?.bloodPressure) {
    return String(activeVitalRecord.value.bloodPressure);
  }
  return "—";
});

// Other Vital Sign Metrics
const hr = computed<number | null>(() => {
  const v = activeVitalRecord.value;
  return v?.heartRateBpm ?? v?.pulse ?? v?.heartRate ?? null;
});

const spo2 = computed<number | null>(() => {
  const v = activeVitalRecord.value;
  return v?.oxygenSaturationPct ?? v?.spo2 ?? v?.oxygenSaturation ?? null;
});

const temp = computed<number | null>(() => {
  const v = activeVitalRecord.value;
  return v?.temperatureC ?? v?.temperature ?? null;
});

const rr = computed<number | null>(() => {
  const v = activeVitalRecord.value;
  return v?.respiratoryRateBpm ?? v?.respiratoryRate ?? null;
});

const pain = computed<number | null>(() => {
  const v = activeVitalRecord.value;
  return v?.painScore ?? null;
});

const weight = computed<number | null>(() => {
  const v = activeVitalRecord.value;
  return v?.weightKg ?? v?.weight ?? null;
});

const height = computed<number | null>(() => {
  const v = activeVitalRecord.value;
  return v?.heightCm ?? v?.height ?? null;
});

const bmi = computed<string>(() => {
  const v = activeVitalRecord.value;
  if (v?.bmi != null) {
    return Number(v.bmi).toFixed(1);
  }
  if (weight.value && height.value) {
    const hM = height.value / 100;
    return (weight.value / (hM * hM)).toFixed(1);
  }
  return "—";
});

const recordedAtFormatted = computed<string | null>(() => {
  const v = activeVitalRecord.value;
  const raw = v?.recordedAt ?? v?.created_at;
  if (!raw) return null;
  try {
    return new Date(raw).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  } catch {
    return null;
  }
});

// NEWS2 Score Engine (Calculated strictly from non-null measurements)
const newsScore = computed(() => {
  if (!hasVitals.value) return null;

  let total = 0;
  let hasRedFlag = false;

  if (rr.value != null) {
    if (rr.value <= 8 || rr.value >= 25) { total += 3; hasRedFlag = true; }
    else if (rr.value >= 21) total += 2;
    else if (rr.value >= 9 && rr.value <= 11) total += 1;
  }

  if (spo2.value != null) {
    if (spo2.value <= 91) { total += 3; hasRedFlag = true; }
    else if (spo2.value <= 93) total += 2;
    else if (spo2.value <= 95) total += 1;
  }

  if (sbp.value != null) {
    if (sbp.value <= 90 || sbp.value >= 220) { total += 3; hasRedFlag = true; }
    else if (sbp.value <= 100) total += 2;
    else if (sbp.value <= 110) total += 1;
  }

  if (hr.value != null) {
    if (hr.value <= 40 || hr.value >= 131) { total += 3; hasRedFlag = true; }
    else if (hr.value >= 111) total += 2;
    else if ((hr.value >= 41 && hr.value <= 50) || (hr.value >= 91 && hr.value <= 110)) total += 1;
  }

  if (temp.value != null) {
    if (temp.value <= 35.0) { total += 3; hasRedFlag = true; }
    else if (temp.value >= 39.1) total += 2;
    else if (temp.value <= 36.0 || temp.value >= 38.1) total += 1;
  }

  if (total >= 5 || hasRedFlag) {
    return {
      score: total,
      level: "high" as const,
      label: total >= 7 ? "High Clinical Risk (NEWS2 ≥ 7)" : "Urgent Clinical Alert (Extreme Vital)",
      badgeVariant: "critical" as const,
    };
  }
  if (total >= 1) {
    return {
      score: total,
      level: "medium" as const,
      label: "Medium Clinical Risk (NEWS2 1-4)",
      badgeVariant: "warning" as const,
    };
  }
  return {
    score: total,
    level: "low" as const,
    label: "Low Clinical Risk (NEWS2 0)",
    badgeVariant: "success" as const,
  };
});
</script>

<template>
  <div class="space-y-3 p-3.5">
    <!-- Loading State: Fetching vital records from server -->
    <div v-if="vitalsStore.isLoading && !hasVitals" class="py-12 flex flex-col items-center justify-center gap-2">
      <Loader2 class="size-6 animate-spin text-primary" aria-hidden="true" />
      <span class="text-xs text-muted-foreground font-medium">{{ t("common.loading") || "Loading clinical vitals..." }}</span>
    </div>

    <!-- Empty State: No vitals recorded -->
    <div v-else-if="!hasVitals" class="py-6">
      <EmptyState
        illustration="activity"
        badge="Clinical Acuity"
        :title="t('vitals.no_vitals_title')"
        :description="t('vitals.no_vitals_desc')"
      />
    </div>

    <!-- Active Vitals & Acuity View -->
    <div v-else class="space-y-3">
      <!-- NEWS2 & Acuity Banner -->
      <div
        v-if="newsScore"
        class="rounded-lg border p-2.5 flex items-center justify-between gap-2.5 flex-wrap transition-colors"
        :class="
          newsScore.level === 'high'
            ? 'bg-critical/10 border-critical/30'
            : newsScore.level === 'medium'
              ? 'bg-warning/10 border-warning/30'
              : 'bg-emerald-500/10 border-emerald-500/30'
        "
      >
        <div class="flex items-center gap-2.5">
          <div
            class="flex size-8 items-center justify-center rounded-md font-mono text-xs font-bold shrink-0"
            :class="
              newsScore.level === 'high'
                ? 'bg-critical text-white'
                : newsScore.level === 'medium'
                  ? 'bg-warning text-warning-foreground'
                  : 'bg-emerald-600 text-white'
            "
          >
            {{ newsScore.score }}
          </div>
          <div>
            <h3 class="font-bold text-foreground text-xs">
              {{ t("nursing.triage_acuity") }}: {{ newsScore.label }}
            </h3>
            <p class="text-[11px] text-muted-foreground mt-0.5">
              {{ t("nursing.baseline_recorded_desc") }}
              <span v-if="recordedAtFormatted" class="font-mono ml-1">· {{ recordedAtFormatted }}</span>
            </p>
          </div>
        </div>

        <Badge
          :variant="newsScore.badgeVariant"
          class="text-[10.5px] font-mono uppercase px-1.5 py-0"
        >
          {{ newsScore.level === 'high' ? 'STAT Alert' : newsScore.level === 'medium' ? 'Monitor' : 'Stable' }}
        </Badge>
      </div>

      <!-- Vital Sign Metric Cards Grid -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2.5">
        <!-- 1. Blood Pressure -->
        <div class="rounded-lg border border-border/70 bg-card/60 p-2.5 space-y-1">
          <div class="flex items-center justify-between text-xs text-muted-foreground">
            <span class="font-medium">{{ t("vitals.blood_pressure") }}</span>
            <Activity class="size-3.5 text-primary" />
          </div>
          <div class="flex items-baseline gap-1">
            <span class="text-lg font-bold font-mono text-foreground">{{ bp }}</span>
            <span v-if="bp !== '—'" class="text-[11px] text-muted-foreground font-mono">mmHg</span>
          </div>
          <span class="text-[10px] text-emerald-600 font-medium block">Ref: 90-120 / 60-80</span>
        </div>

        <!-- 2. Pulse / Heart Rate -->
        <div class="rounded-lg border border-border/70 bg-card/60 p-2.5 space-y-1">
          <div class="flex items-center justify-between text-xs text-muted-foreground">
            <span class="font-medium">{{ t("vitals.pulse") }}</span>
            <Heart class="size-3.5 text-critical" />
          </div>
          <div class="flex items-baseline gap-1">
            <span class="text-lg font-bold font-mono text-foreground">{{ hr ?? '—' }}</span>
            <span v-if="hr != null" class="text-[11px] text-muted-foreground font-mono">bpm</span>
          </div>
          <span class="text-[10px] text-emerald-600 font-medium block">Ref: 60-100</span>
        </div>

        <!-- 3. Oxygen Saturation (SpO2) -->
        <div class="rounded-lg border border-border/70 bg-card/60 p-2.5 space-y-1">
          <div class="flex items-center justify-between text-xs text-muted-foreground">
            <span class="font-medium">{{ t("vitals.spo2") }}</span>
            <Wind class="size-3.5 text-blue-600 dark:text-blue-400" />
          </div>
          <div class="flex items-baseline gap-1">
            <span class="text-lg font-bold font-mono text-foreground">{{ spo2 ?? '—' }}</span>
            <span v-if="spo2 != null" class="text-[11px] text-muted-foreground font-mono">%</span>
          </div>
          <span class="text-[10px] text-emerald-600 font-medium block">Ref: 95-100%</span>
        </div>

        <!-- 4. Temperature -->
        <div class="rounded-lg border border-border/70 bg-card/60 p-2.5 space-y-1">
          <div class="flex items-center justify-between text-xs text-muted-foreground">
            <span class="font-medium">{{ t("vitals.temperature") }}</span>
            <Thermometer class="size-3.5 text-amber-600 dark:text-amber-400" />
          </div>
          <div class="flex items-baseline gap-1">
            <span class="text-lg font-bold font-mono text-foreground">{{ temp ?? '—' }}</span>
            <span v-if="temp != null" class="text-[11px] text-muted-foreground font-mono">°C</span>
          </div>
          <span class="text-[10px] text-emerald-600 font-medium block">Ref: 36.5 - 37.5°C</span>
        </div>

        <!-- 5. Respiratory Rate -->
        <div class="rounded-lg border border-border/70 bg-card/60 p-2.5 space-y-1">
          <div class="flex items-center justify-between text-xs text-muted-foreground">
            <span class="font-medium">{{ t("vitals.respiratory_rate") }}</span>
            <Activity class="size-3.5 text-indigo-600 dark:text-indigo-400" />
          </div>
          <div class="flex items-baseline gap-1">
            <span class="text-lg font-bold font-mono text-foreground">{{ rr ?? '—' }}</span>
            <span v-if="rr != null" class="text-[11px] text-muted-foreground font-mono">/min</span>
          </div>
          <span class="text-[10px] text-emerald-600 font-medium block">Ref: 12-20</span>
        </div>

        <!-- 6. Pain Score -->
        <div class="rounded-lg border border-border/70 bg-card/60 p-2.5 space-y-1">
          <div class="flex items-center justify-between text-xs text-muted-foreground">
            <span class="font-medium">{{ t("vitals.pain_score") }}</span>
            <Smile class="size-3.5 text-muted-foreground" />
          </div>
          <div class="flex items-baseline gap-1">
            <span class="text-lg font-bold font-mono text-foreground">{{ pain ?? '—' }}</span>
            <span v-if="pain != null" class="text-[11px] text-muted-foreground font-mono">/ 10</span>
          </div>
          <span class="text-[10px] text-muted-foreground font-medium block">VAS Pain Scale</span>
        </div>

        <!-- 7. Weight & Height -->
        <div class="rounded-lg border border-border/70 bg-card/60 p-2.5 space-y-1 sm:col-span-2">
          <div class="flex items-center justify-between text-xs text-muted-foreground">
            <span class="font-medium">{{ t("vitals.weight_and_height") }}</span>
            <Scale class="size-3.5 text-primary" />
          </div>
          <div class="flex items-center gap-3 flex-wrap pt-0.5 text-xs">
            <div>
              <span class="text-sm font-bold font-mono text-foreground">{{ weight ?? '—' }}</span>
              <span v-if="weight != null" class="text-[11px] text-muted-foreground font-mono ml-1">kg</span>
            </div>
            <div class="border-l border-border pl-2.5">
              <span class="text-sm font-bold font-mono text-foreground">{{ height ?? '—' }}</span>
              <span v-if="height != null" class="text-[11px] text-muted-foreground font-mono ml-1">cm</span>
            </div>
            <div class="border-l border-border pl-2.5">
              <span class="text-muted-foreground">BMI:</span>
              <span class="text-sm font-bold font-mono text-foreground ml-1">{{ bmi }}</span>
              <span v-if="bmi !== '—'" class="text-[10px] text-muted-foreground ml-1">kg/m²</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Historical Vitals Log (if multiple recorded sets exist) -->
      <div v-if="vitalsStore.history.length > 1" class="pt-2 border-t border-border/60">
        <h4 class="text-xs font-semibold text-foreground mb-2 flex items-center gap-1.5">
          <Clock class="size-3.5 text-muted-foreground" />
          <span>{{ t("nursing.vital_history") || "Vital Trends & History" }}</span>
          <Badge variant="secondary" class="text-[10px] font-mono px-1 py-0 ml-1">
            {{ vitalsStore.history.length }}
          </Badge>
        </h4>
        <div class="space-y-1.5">
          <div
            v-for="record in vitalsStore.history"
            :key="record.id"
            class="flex items-center justify-between text-xs p-2 rounded border border-border/50 bg-surface/50 gap-2 flex-wrap"
          >
            <span class="text-[11px] text-muted-foreground font-mono">
              {{ record.recordedAt ? formatClinicalDate(record.recordedAt) : 'Recent' }}
            </span>
            <div class="flex items-center gap-3 font-mono text-[11px] text-foreground flex-wrap">
              <span v-if="record.systolicBpMmhg && record.diastolicBpMmhg">
                BP: {{ record.systolicBpMmhg }}/{{ record.diastolicBpMmhg }}
              </span>
              <span v-if="record.heartRateBpm">HR: {{ record.heartRateBpm }} bpm</span>
              <span v-if="record.temperatureC">Temp: {{ record.temperatureC }}°C</span>
              <span v-if="record.oxygenSaturationPct">SpO2: {{ record.oxygenSaturationPct }}%</span>
              <span v-if="record.weightKg">Wt: {{ record.weightKg }} kg</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
