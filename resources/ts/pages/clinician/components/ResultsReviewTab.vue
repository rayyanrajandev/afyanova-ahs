/**
 * ResultsReviewTab — Diagnostic Lab & Radiology Results Review (Volume 2.2 §9)
 * ==============================================================================
 * Renders verified diagnostic test results, clinical reference ranges,
 * critical abnormal flags, and doctor sign-off acknowledgments.
 */

<script setup lang="ts">
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  Eye,
  FileCheck,
  FlaskConical,
  Radio,
  RefreshCw,
  ShieldAlert,
} from "lucide-vue-next";
import { onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { formatClinicalDate } from "@/pages/reception/receptionFormatters";
import type { useClinicianResults } from "../composables/useClinicianResults";

const props = defineProps<{
  patientId: string | null;
  resultsManager: ReturnType<typeof useClinicianResults>;
}>();

const { t } = useI18n({ useScope: "global" });

onMounted(() => {
  if (props.patientId) {
    props.resultsManager.fetchResults(props.patientId);
  } else {
    props.resultsManager.fetchResults();
  }
});
</script>

<template>
  <div class="space-y-3 p-3.5">
    <!-- Results Header -->
    <div class="flex items-center justify-between gap-3 border-b border-border/60 pb-2 flex-wrap">
      <div class="flex items-center gap-2">
        <FlaskConical class="size-4 text-primary" />
        <h3 class="text-xs font-bold text-foreground">{{ t("clinician.results_review") }}</h3>
      </div>
      <Button
        variant="ghost"
        size="sm"
        class="h-7 gap-1.5 text-xs text-muted-foreground hover:text-foreground cursor-pointer"
        @click="resultsManager.fetchResults(patientId ?? undefined)"
      >
        <RefreshCw class="size-3" :class="{ 'animate-spin': resultsManager.isResultsLoading.value }" />
        <span>{{ t("common.retry") }}</span>
      </Button>
    </div>

    <!-- Results Cards / List -->
    <div v-if="resultsManager.isResultsLoading.value" class="space-y-2.5">
      <div v-for="n in 3" :key="n" class="h-16 rounded-lg bg-card border border-border animate-pulse" />
    </div>

    <div v-else-if="resultsManager.results.value.length === 0" class="rounded-lg border border-border bg-card p-5 text-center text-xs text-muted-foreground">
      {{ t("clinician.select_result_hint") }}
    </div>

    <div v-else class="space-y-2.5">
      <div
        v-for="result in resultsManager.results.value"
        :key="result.id"
        class="rounded-lg border bg-card/60 p-3 space-y-2"
        :class="
          result.flag === 'critical'
            ? 'border-critical/50'
            : result.flag === 'abnormal'
              ? 'border-warning/50'
              : 'border-border/70'
        "
      >
        <div class="flex flex-row items-center justify-between gap-2 pb-1.5 border-b border-border/50">
          <div class="flex items-center gap-2 min-w-0">
            <Badge
              :variant="result.category === 'imaging' ? 'info' : 'default'"
              class="uppercase text-[9px] font-mono px-1.5 py-0 shrink-0"
            >
              {{ result.category }}
            </Badge>
            <h4 class="font-bold text-foreground text-xs truncate">{{ result.testName }}</h4>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            <!-- Flag Badge -->
            <Badge
              :variant="result.flag === 'critical' ? 'critical' : result.flag === 'abnormal' ? 'warning' : 'success'"
              class="text-[9.5px] font-bold uppercase gap-1 px-1.5 py-0"
            >
              <AlertTriangle v-if="result.flag === 'critical'" class="size-3 animate-pulse" />
              <span>{{ t(`clinician.flag_${result.flag}`) }}</span>
            </Badge>

            <!-- Acknowledge Button -->
            <Button
              v-if="!result.isAcknowledged"
              size="sm"
              variant="outline"
              class="h-6 gap-1 px-2 text-[10.5px] font-semibold text-primary border-primary/40 hover:bg-primary/10 cursor-pointer"
              @click="resultsManager.acknowledgeResult(result.id)"
            >
              <FileCheck class="size-3" />
              <span>{{ t("clinician.acknowledge_result") }}</span>
            </Button>
            <span v-else class="inline-flex items-center gap-1 text-[10.5px] font-medium text-emerald-600 dark:text-emerald-400">
              <CheckCircle2 class="size-3.5" />
              {{ t("clinician.acknowledged") }}
            </span>
          </div>
        </div>

        <div class="space-y-2 text-xs pt-1">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 text-xs">
            <div>
              <span class="text-muted-foreground text-[10.5px] block">{{ t("clinician.value") }}</span>
              <span class="font-bold text-sm font-mono text-foreground">{{ result.value }}</span>
              <span v-if="result.unit" class="text-xs text-muted-foreground ml-1 font-mono">{{ result.unit }}</span>
            </div>
            <div>
              <span class="text-muted-foreground text-[10.5px] block">{{ t("clinician.reference") }}</span>
              <span class="font-mono text-foreground font-medium">{{ result.referenceRange }}</span>
            </div>
            <div>
              <span class="text-muted-foreground text-[10.5px] block">{{ t("clinician.date") }}</span>
              <span class="font-mono text-muted-foreground text-[11px]">{{ formatClinicalDate(result.performedAt) }} · {{ result.technicianName }}</span>
            </div>
          </div>

          <div v-if="result.interpretation" class="border-t border-border/60 pt-2 text-xs">
            <span class="text-muted-foreground text-[10.5px] block font-semibold">{{ t("clinician.impression") }}:</span>
            <p class="text-foreground leading-relaxed mt-0.5">{{ result.interpretation }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
