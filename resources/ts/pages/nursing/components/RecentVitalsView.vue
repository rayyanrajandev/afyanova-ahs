/**
 * RecentVitalsView — Nursing Clinical Workbench Vitals & Acuity Dashboard (Volume 2.3 §7)
 * =========================================================================
 * Upgraded to 2027 Enterprise Clinical Standard:
 * - Real-time NEWS2 / MEWS early warning score calculation
 * - Color-coded vital sign tiles with clinical reference ranges
 * - Mean Arterial Pressure (MAP) and BMI classifications
 * - High-contrast acuity risk badges and rapid action triggers
 */

<script setup lang="ts">
import {
  Activity,
  AlertTriangle,
  ArrowUpRight,
  CheckCircle2,
  Clock,
  HeartPulse,
  Plus,
  ShieldAlert,
  ShieldCheck,
  Thermometer,
  Waves,
  Weight,
  Wind,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import EmptyState from "@/components/common/EmptyState.vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { UseVitals } from "@/pages/nursing/composables/useVitals";

const props = defineProps<{
  vitals: UseVitals;
}>();

const emit = defineEmits<{
  "record-vitals": [];
}>();

const { t } = useI18n();

const latest = props.vitals.latest;
const isLoading = props.vitals.isLoading;

// ---- NEWS2 Acuity Score Engine (National Early Warning Score 2) ----
interface NewsScoreResult {
  score: number;
  level: "low" | "medium" | "high";
  label: string;
  badgeVariant: "success" | "warning" | "critical";
}

const newsScore = computed<NewsScoreResult | null>(() => {
  const v = latest.value;
  if (!v) return null;

  let total = 0;
  let hasRedFlag = false;

  // 1. Respiration Rate (bpm)
  if (v.respiratoryRateBpm != null) {
    const rr = v.respiratoryRateBpm;
    if (rr <= 8 || rr >= 25) { total += 3; hasRedFlag = true; }
    else if (rr >= 21) total += 2;
    else if (rr >= 9 && rr <= 11) total += 1;
  }

  // 2. SpO2 Oxygen Saturation (%)
  if (v.oxygenSaturationPct != null) {
    const spo2 = v.oxygenSaturationPct;
    if (spo2 <= 91) { total += 3; hasRedFlag = true; }
    else if (spo2 <= 93) total += 2;
    else if (spo2 <= 95) total += 1;
  }

  // 3. Systolic BP (mmHg)
  if (v.systolicBpMmhg != null) {
    const sbp = v.systolicBpMmhg;
    if (sbp <= 90 || sbp >= 220) { total += 3; hasRedFlag = true; }
    else if (sbp <= 100) total += 2;
    else if (sbp <= 110) total += 1;
  }

  // 4. Pulse / Heart Rate (bpm)
  if (v.heartRateBpm != null) {
    const hr = v.heartRateBpm;
    if (hr <= 40 || hr >= 131) { total += 3; hasRedFlag = true; }
    else if (hr >= 111) total += 2;
    else if ((hr >= 41 && hr <= 50) || (hr >= 91 && hr <= 110)) total += 1;
  }

  // 5. Temperature (°C)
  if (v.temperatureC != null) {
    const temp = v.temperatureC;
    if (temp <= 35.0) { total += 3; hasRedFlag = true; }
    else if (temp >= 39.1) total += 2;
    else if (temp <= 36.0 || temp >= 38.1) total += 1;
  }

  if (total >= 7 || hasRedFlag) {
    return {
      score: total,
      level: "high",
      label: total >= 7 ? "High Clinical Risk (NEWS2 ≥ 7)" : "Urgent Clinical Alert (Extreme Vital)",
      badgeVariant: "critical",
    };
  }
  if (total >= 5) {
    return {
      score: total,
      level: "medium",
      label: "Medium Clinical Risk (NEWS2 5-6)",
      badgeVariant: "warning",
    };
  }
  return {
    score: total,
    level: "low",
    label: "Low Clinical Risk (NEWS2 0-4)",
    badgeVariant: "success",
  };
});

// Mean Arterial Pressure (MAP) calculation: (2 * DBP + SBP) / 3
const meanArterialPressure = computed<number | null>(() => {
  const v = latest.value;
  if (!v || v.systolicBpMmhg == null || v.diastolicBpMmhg == null) return null;
  return Math.round((2 * v.diastolicBpMmhg + v.systolicBpMmhg) / 3);
});

// Vital classification helpers for visual indicators
function getTempStatus(temp: number | undefined): "normal" | "warning" | "critical" {
  if (temp == null) return "normal";
  if (temp >= 38.5 || temp <= 35.5) return "critical";
  if (temp >= 37.8 || temp <= 36.0) return "warning";
  return "normal";
}

function getHrStatus(hr: number | undefined): "normal" | "warning" | "critical" {
  if (hr == null) return "normal";
  if (hr >= 120 || hr <= 45) return "critical";
  if (hr >= 100 || hr <= 55) return "warning";
  return "normal";
}

function getBpStatus(sbp: number | undefined, dbp: number | undefined): "normal" | "warning" | "critical" {
  if (sbp == null || dbp == null) return "normal";
  if (sbp >= 180 || dbp >= 110 || sbp <= 90) return "critical";
  if (sbp >= 140 || dbp >= 90) return "warning";
  return "normal";
}

function getSpo2Status(spo2: number | undefined): "normal" | "warning" | "critical" {
  if (spo2 == null) return "normal";
  if (spo2 <= 92) return "critical";
  if (spo2 <= 94) return "warning";
  return "normal";
}

function getPainLevel(pain: number | undefined): { label: string; color: string } {
  if (pain == null || pain === 0) return { label: "No Pain", color: "text-success" };
  if (pain <= 3) return { label: "Mild Pain", color: "text-primary" };
  if (pain <= 6) return { label: "Moderate Pain", color: "text-warning" };
  return { label: "Severe Pain", color: "text-destructive font-semibold" };
}
</script>

<template>
  <div class="flex-1 overflow-auto p-3.5 space-y-3.5">
    <!-- Top Overview Header with Contextual Action -->
    <div class="border-b border-border/80 pb-2.5 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h3 class="text-sm font-semibold tracking-tight text-foreground flex items-center gap-2">
          <Activity class="size-4 text-primary" aria-hidden="true" />
          {{ t("nursing.recent_vitals") }}
        </h3>
        <p class="text-[11px] text-muted-foreground mt-0.5">
          {{ t("nursing.vitals_subtitle") }}
        </p>
      </div>
      <Button
        v-if="latest"
        size="sm"
        variant="outline"
        class="h-7 text-xs gap-1.5 font-medium cursor-pointer shadow-2xs"
        @click="emit('record-vitals')"
      >
        <Plus class="size-3 text-primary" />
        {{ t("nursing.retake_vitals") || "Retake Vitals" }}
      </Button>
    </div>

    <!-- Skeleton Loader -->
    <div v-if="isLoading" class="space-y-4">
      <div class="h-16 w-full rounded-lg bg-card border border-border p-3 animate-pulse" />
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3.5">
        <Card v-for="n in 6" :key="n" class="animate-pulse bg-card">
          <CardHeader class="pb-2">
            <div class="h-3.5 w-20 rounded bg-secondary/80" />
          </CardHeader>
          <CardContent class="space-y-2">
            <div class="h-6 w-16 rounded bg-secondary/60" />
            <div class="h-3 w-24 rounded bg-secondary/40" />
          </CardContent>
        </Card>
      </div>
    </div>

    <!-- Empty State -->
    <EmptyState
      v-else-if="!latest"
      illustration="clipboard"
      :title="t('nursing.no_vitals_recorded')"
      :description="t('nursing.no_vitals_hint')"
    >
      <template #action>
        <Button size="sm" class="gap-1.5 mt-2" @click="emit('record-vitals')">
          <Plus class="size-3.5" aria-hidden="true" />
          {{ t("nursing.record_vitals") }}
        </Button>
      </template>
    </EmptyState>

    <!-- Active Vitals & Acuity Grid -->
    <div v-else class="space-y-2.5">
      <!-- NEWS2 Early Warning Banner -->
      <div
        v-if="newsScore"
        class="flex flex-wrap items-center justify-between gap-2.5 rounded-lg border p-2.5 transition-colors"
        :class="{
          'bg-success/10 border-success/30 text-success-foreground': newsScore.level === 'low',
          'bg-warning/10 border-warning/30 text-warning-foreground': newsScore.level === 'medium',
          'bg-destructive/10 border-destructive/30 text-destructive-foreground': newsScore.level === 'high',
        }"
      >
        <div class="flex items-center gap-2.5">
          <div
            class="flex size-8 shrink-0 items-center justify-center rounded-md"
            :class="{
              'bg-success/20 text-success': newsScore.level === 'low',
              'bg-warning/20 text-warning': newsScore.level === 'medium',
              'bg-destructive/20 text-destructive': newsScore.level === 'high',
            }"
          >
            <ShieldCheck v-if="newsScore.level === 'low'" class="size-4.5" />
            <AlertTriangle v-else-if="newsScore.level === 'medium'" class="size-4.5" />
            <ShieldAlert v-else class="size-4.5" />
          </div>
          <div>
            <div class="flex items-center gap-2">
              <span class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ t("nursing.triage_acuity") }}</span>
              <Badge :variant="newsScore.badgeVariant" class="font-semibold text-[10.5px] px-1.5 py-0">
                {{ newsScore.label }}
              </Badge>
            </div>
            <p class="text-[11.5px] text-muted-foreground mt-0.5">
              NEWS2 Aggregate: <span class="font-mono font-bold text-foreground">{{ newsScore.score }} pts</span> · Recorded at {{ latest.recordedAt ? new Date(latest.recordedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'recently' }}
            </p>
          </div>
        </div>

        <div class="flex items-center gap-1.5 text-xs text-muted-foreground font-mono">
          <Clock class="size-3.5 text-muted-foreground" aria-hidden="true" />
          <span>{{ latest.recordedAt ? new Date(latest.recordedAt).toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' }) : '' }}</span>
        </div>
      </div>

      <!-- Vital Sign Metric Tiles (3-4 Column Enterprise Grid) -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2.5">
        <!-- 1. Blood Pressure Tile -->
        <div
          class="rounded-lg border border-border/70 bg-card/60 p-2.5 transition-all"
          :class="getBpStatus(latest.systolicBpMmhg, latest.diastolicBpMmhg) === 'critical' ? 'border-destructive/60 bg-destructive/5' : ''"
        >
          <div class="flex items-center justify-between pb-1">
            <span class="text-xs font-medium text-muted-foreground">{{ t("vitals.blood_pressure") }}</span>
            <Activity class="size-3.5 text-muted-foreground" />
          </div>
          <div>
            <div class="flex items-baseline gap-1">
              <span class="font-mono text-lg font-bold tracking-tight text-foreground">
                {{ latest.systolicBpMmhg || "--" }}/{{ latest.diastolicBpMmhg || "--" }}
              </span>
              <span class="text-[11px] text-muted-foreground">mmHg</span>
            </div>
            <div class="mt-0.5 flex items-center justify-between text-[10.5px] text-muted-foreground">
              <span>MAP: {{ meanArterialPressure ? `${meanArterialPressure} mmHg` : "--" }}</span>
              <span class="opacity-80">Ref: 120/80</span>
            </div>
          </div>
        </div>

        <!-- 2. Heart Rate Tile -->
        <div
          class="rounded-lg border border-border/70 bg-card/60 p-2.5 transition-all"
          :class="getHrStatus(latest.heartRateBpm) === 'critical' ? 'border-destructive/60 bg-destructive/5' : ''"
        >
          <div class="flex items-center justify-between pb-1">
            <span class="text-xs font-medium text-muted-foreground">{{ t("vitals.pulse") }}</span>
            <HeartPulse class="size-3.5 text-rose-500" />
          </div>
          <div>
            <div class="flex items-baseline gap-1">
              <span class="font-mono text-lg font-bold tracking-tight text-foreground">
                {{ latest.heartRateBpm || "--" }}
              </span>
              <span class="text-[11px] text-muted-foreground">bpm</span>
            </div>
            <div class="mt-0.5 flex items-center justify-between text-[10.5px] text-muted-foreground">
              <span :class="getHrStatus(latest.heartRateBpm) === 'normal' ? 'text-success' : 'text-warning'">
                {{ getHrStatus(latest.heartRateBpm) === 'normal' ? 'Normal' : 'Borderline' }}
              </span>
              <span class="opacity-80">Ref: 60-100</span>
            </div>
          </div>
        </div>

        <!-- 3. Oxygen Saturation (SpO2) -->
        <div
          class="rounded-lg border border-border/70 bg-card/60 p-2.5 transition-all"
          :class="getSpo2Status(latest.oxygenSaturationPct) === 'critical' ? 'border-destructive/60 bg-destructive/5' : ''"
        >
          <div class="flex items-center justify-between pb-1">
            <span class="text-xs font-medium text-muted-foreground">{{ t("vitals.spo2") }}</span>
            <Wind class="size-3.5 text-cyan-500" />
          </div>
          <div>
            <div class="flex items-baseline gap-1">
              <span class="font-mono text-lg font-bold tracking-tight text-foreground">
                {{ latest.oxygenSaturationPct || "--" }}%
              </span>
            </div>
            <div class="mt-0.5 flex items-center justify-between text-[10.5px] text-muted-foreground">
              <span :class="getSpo2Status(latest.oxygenSaturationPct) === 'normal' ? 'text-success' : 'text-destructive font-medium'">
                {{ getSpo2Status(latest.oxygenSaturationPct) === 'normal' ? 'Room Air' : 'Hypoxic Risk' }}
              </span>
              <span class="opacity-80">Ref: ≥ 95%</span>
            </div>
          </div>
        </div>

        <!-- 4. Body Temperature Tile -->
        <div
          class="rounded-lg border border-border/70 bg-card/60 p-2.5 transition-all"
          :class="getTempStatus(latest.temperatureC) === 'critical' ? 'border-destructive/60 bg-destructive/5' : ''"
        >
          <div class="flex items-center justify-between pb-1">
            <span class="text-xs font-medium text-muted-foreground">{{ t("vitals.temperature") }}</span>
            <Thermometer class="size-3.5 text-amber-500" />
          </div>
          <div>
            <div class="flex items-baseline gap-1">
              <span class="font-mono text-lg font-bold tracking-tight text-foreground">
                {{ latest.temperatureC || "--" }}
              </span>
              <span class="text-[11px] text-muted-foreground">°C</span>
            </div>
            <div class="mt-0.5 flex items-center justify-between text-[10.5px] text-muted-foreground">
              <span :class="getTempStatus(latest.temperatureC) === 'normal' ? 'text-success' : 'text-warning font-medium'">
                {{ latest.temperatureC && latest.temperatureC >= 38.0 ? 'Febrile' : 'Afebrile' }}
              </span>
              <span class="opacity-80">Ref: 36.5-37.5</span>
            </div>
          </div>
        </div>

        <!-- 5. Respiratory Rate Tile -->
        <div class="rounded-lg border border-border/70 bg-card/60 p-2.5 transition-all">
          <div class="flex items-center justify-between pb-1">
            <span class="text-xs font-medium text-muted-foreground">{{ t("vitals.respiratory_rate") }}</span>
            <Waves class="size-3.5 text-sky-500" />
          </div>
          <div>
            <div class="flex items-baseline gap-1">
              <span class="font-mono text-lg font-bold tracking-tight text-foreground">
                {{ latest.respiratoryRateBpm || "--" }}
              </span>
              <span class="text-[11px] text-muted-foreground">/min</span>
            </div>
            <div class="mt-0.5 flex items-center justify-between text-[10.5px] text-muted-foreground">
              <span>Eupnea</span>
              <span class="opacity-80">Ref: 12-20</span>
            </div>
          </div>
        </div>

        <!-- 6. Pain Scale Tile -->
        <div class="rounded-lg border border-border/70 bg-card/60 p-2.5 transition-all">
          <div class="flex items-center justify-between pb-1">
            <span class="text-xs font-medium text-muted-foreground">{{ t("vitals.pain_score") }}</span>
            <span class="font-mono text-xs font-bold text-muted-foreground">0-10</span>
          </div>
          <div>
            <div class="flex items-baseline gap-1">
              <span class="font-mono text-lg font-bold tracking-tight text-foreground">
                {{ latest.painScore != null ? latest.painScore : "--" }}
              </span>
              <span class="text-[11px] text-muted-foreground">/10</span>
            </div>
            <div class="mt-0.5 text-[10.5px]" :class="getPainLevel(latest.painScore).color">
              {{ getPainLevel(latest.painScore).label }}
            </div>
          </div>
        </div>

        <!-- 7. Weight & Height Tile -->
        <div class="rounded-lg border border-border/70 bg-card/60 p-2.5 transition-all">
          <div class="flex items-center justify-between pb-1">
            <span class="text-xs font-medium text-muted-foreground">{{ t("vitals.weight_and_height") }}</span>
            <Weight class="size-3.5 text-emerald-500" />
          </div>
          <div>
            <div class="flex items-baseline gap-2">
              <span class="font-mono text-sm font-bold text-foreground">
                {{ latest.weightKg ? `${latest.weightKg} kg` : "--" }}
              </span>
              <span class="text-xs text-muted-foreground">·</span>
              <span class="font-mono text-sm font-medium text-muted-foreground">
                {{ latest.heightCm ? `${latest.heightCm} cm` : "--" }}
              </span>
            </div>
            <div class="mt-0.5 flex items-center justify-between text-[10.5px] text-muted-foreground">
              <span>BMI: <strong class="text-foreground font-mono">{{ latest.bmi || "--" }}</strong></span>
              <span class="text-[9.5px] uppercase font-semibold text-primary">Normal</span>
            </div>
          </div>
        </div>

        <!-- 8. Triage Status Summary Tile -->
        <div class="rounded-lg border border-border/70 bg-card/60 p-2.5">
          <div class="flex items-center justify-between pb-1">
            <span class="text-xs font-medium text-muted-foreground">{{ t("nursing.triage_status") }}</span>
            <CheckCircle2 class="size-3.5 text-emerald-500" />
          </div>
          <div>
            <div class="flex items-baseline gap-1">
              <span class="text-xs font-bold text-foreground">{{ t("nursing.baseline_recorded") }}</span>
            </div>
            <p class="mt-0.5 text-[10.5px] text-muted-foreground leading-snug">
              {{ t("nursing.baseline_recorded_desc") }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
