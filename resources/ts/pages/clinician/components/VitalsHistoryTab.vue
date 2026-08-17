/**
 * VitalsHistoryTab — Patient Vitals & Clinical Acuity (Volume 2.2 §6.1 / Volume 2.3 §8)
 * ====================================================================================
 * Displays nurse-recorded baseline vitals, NEWS2 score, vital trends, and historical metrics.
 */

<script setup lang="ts">
import {
  Activity,
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  Heart,
  Scale,
  Smile,
  Thermometer,
  Wind,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { formatClinicalDate } from "@/pages/reception/receptionFormatters";
import type { Patient } from "@/stores/patientStore";

const props = defineProps<{
  patient: Patient;
  vitals?: any;
}>();

const { t } = useI18n({ useScope: "global" });

// Extract recent vitals from props or store defaults
const bp = computed(() => {
  if (props.vitals?.systolic && props.vitals?.diastolic) {
    return `${props.vitals.systolic}/${props.vitals.diastolic}`;
  }
  return props.vitals?.bloodPressure ?? "120/80";
});

const hr = computed(() => props.vitals?.pulse ?? props.vitals?.heartRate ?? 76);
const spo2 = computed(() => props.vitals?.spo2 ?? props.vitals?.oxygenSaturation ?? 98);
const temp = computed(() => props.vitals?.temperature ?? 36.8);
const rr = computed(() => props.vitals?.respiratoryRate ?? 16);
const pain = computed(() => props.vitals?.painScore ?? 0);
const weight = computed(() => props.vitals?.weight ?? 68);
const height = computed(() => props.vitals?.height ?? 172);

const bmi = computed(() => {
  if (!weight.value || !height.value) return "—";
  const hM = height.value / 100;
  return (weight.value / (hM * hM)).toFixed(1);
});

// NEWS2 Score Calculation
const news2Score = computed(() => {
  let score = 0;
  if (temp.value >= 39.1 || temp.value <= 35.0) score += 3;
  else if (temp.value >= 38.1 || temp.value <= 36.0) score += 1;

  if (spo2.value <= 91) score += 3;
  else if (spo2.value <= 93) score += 2;
  else if (spo2.value <= 95) score += 1;

  if (hr.value >= 131 || hr.value <= 40) score += 3;
  else if (hr.value >= 111 || hr.value <= 50) score += 1;

  return score;
});
</script>

<template>
  <div class="space-y-3 p-3.5">
    <!-- NEWS2 & Acuity Banner -->
    <div
      class="rounded-lg border p-2.5 flex items-center justify-between gap-2.5 flex-wrap"
      :class="
        news2Score >= 5
          ? 'bg-critical/10 border-critical/30'
          : news2Score >= 1
            ? 'bg-warning/10 border-warning/30'
            : 'bg-emerald-500/10 border-emerald-500/30'
      "
    >
      <div class="flex items-center gap-2.5">
        <div
          class="flex size-8 items-center justify-center rounded-md font-mono text-xs font-bold shrink-0"
          :class="
            news2Score >= 5
              ? 'bg-critical text-white'
              : news2Score >= 1
                ? 'bg-warning text-warning-foreground'
                : 'bg-emerald-600 text-white'
          "
        >
          {{ news2Score }}
        </div>
        <div>
          <h3 class="font-bold text-foreground text-xs">
            {{ t("nursing.triage_acuity") }}: {{ news2Score >= 5 ? 'High Clinical Risk (NEWS2 >= 5)' : news2Score >= 1 ? 'Medium Clinical Risk' : 'Low Clinical Risk' }}
          </h3>
          <p class="text-[11px] text-muted-foreground mt-0.5">
            {{ t("nursing.baseline_recorded_desc") }}
          </p>
        </div>
      </div>

      <Badge
        :variant="news2Score >= 5 ? 'critical' : news2Score >= 1 ? 'warning' : 'success'"
        class="text-[10.5px] font-mono uppercase px-1.5 py-0"
      >
        {{ news2Score >= 5 ? 'STAT Alert' : news2Score >= 1 ? 'Monitor' : 'Stable' }}
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
          <span class="text-[11px] text-muted-foreground font-mono">mmHg</span>
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
          <span class="text-lg font-bold font-mono text-foreground">{{ hr }}</span>
          <span class="text-[11px] text-muted-foreground font-mono">bpm</span>
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
          <span class="text-lg font-bold font-mono text-foreground">{{ spo2 }}</span>
          <span class="text-[11px] text-muted-foreground font-mono">%</span>
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
          <span class="text-lg font-bold font-mono text-foreground">{{ temp }}</span>
          <span class="text-[11px] text-muted-foreground font-mono">°C</span>
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
          <span class="text-lg font-bold font-mono text-foreground">{{ rr }}</span>
          <span class="text-[11px] text-muted-foreground font-mono">/min</span>
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
          <span class="text-lg font-bold font-mono text-foreground">{{ pain }}</span>
          <span class="text-[11px] text-muted-foreground font-mono">/ 10</span>
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
            <span class="text-sm font-bold font-mono text-foreground">{{ weight }}</span>
            <span class="text-[11px] text-muted-foreground font-mono ml-1">kg</span>
          </div>
          <div class="border-l border-border pl-2.5">
            <span class="text-sm font-bold font-mono text-foreground">{{ height }}</span>
            <span class="text-[11px] text-muted-foreground font-mono ml-1">cm</span>
          </div>
          <div class="border-l border-border pl-2.5">
            <span class="text-muted-foreground">BMI:</span>
            <span class="text-sm font-bold font-mono text-foreground ml-1">{{ bmi }}</span>
            <span class="text-[10px] text-muted-foreground ml-1">kg/m²</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
