/**
 * ResultsReviewTab — Diagnostic Lab & Radiology Results Review (Volume 2.2 §9)
 * ==============================================================================
 * Clean, consistent clinical results review supporting both Laboratory and
 * Diagnostic Imaging (Radiology) findings with doctor sign-off.
 */

<script setup lang="ts">
import {
  AlertTriangle,
  Check,
  CheckCheck,
  CheckCircle2,
  Clock,
  Eye,
  FileCheck,
  FlaskConical,
  Lock,
  Radio,
  RefreshCw,
  Scan,
  Search,
  ShieldCheck,
} from "lucide-vue-next";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { formatClinicalDate } from "@/pages/reception/receptionFormatters";
import type { DiagnosticResultItem, useClinicianResults } from "../composables/useClinicianResults";

const props = defineProps<{
  patientId: string | null;
  resultsManager: ReturnType<typeof useClinicianResults>;
  clinicalMode?: "active" | "awaiting_start" | "triage_pending" | "read_only" | "completed";
}>();

const { t } = useI18n({ useScope: "global" });

const canAcknowledge = computed<boolean>(() => props.clinicalMode === "active");

const activeCategoryFilter = ref<"all" | "lab" | "imaging">("all");
const searchQuery = ref("");

onMounted(() => {
  if (props.patientId) {
    props.resultsManager.fetchResults(props.patientId);
  } else {
    props.resultsManager.fetchResults();
  }
});

const filteredResults = computed<DiagnosticResultItem[]>(() => {
  let list = props.resultsManager.results.value;

  if (activeCategoryFilter.value === "lab") {
    list = list.filter((r) => r.category === "lab");
  } else if (activeCategoryFilter.value === "imaging") {
    list = list.filter((r) => r.category === "imaging");
  }

  const q = searchQuery.value.trim().toLowerCase();
  if (q) {
    list = list.filter(
      (r) =>
        r.testName.toLowerCase().includes(q) ||
        r.value.toLowerCase().includes(q) ||
        (r.interpretation && r.interpretation.toLowerCase().includes(q)) ||
        (r.technicianName && r.technicianName.toLowerCase().includes(q)) ||
        (r.orderNumber && r.orderNumber.toLowerCase().includes(q)),
    );
  }

  return list;
});
</script>

<template>
  <div class="space-y-3 p-3.5 w-full">
    <!-- Results Header -->
    <div class="flex items-center justify-between gap-3 border-b border-border/60 pb-2 flex-wrap">
      <div class="flex items-center gap-2">
        <FlaskConical class="size-4 text-primary" />
        <h3 class="text-xs font-bold text-foreground">{{ t("clinician.results_review", "Diagnostic Results Review") }}</h3>
        <Badge
          v-if="props.resultsManager.totalResultsCount.value > 0"
          variant="secondary"
          class="font-mono text-[10px] px-1.5 py-0"
        >
          {{ props.resultsManager.totalResultsCount.value }}
        </Badge>
      </div>

      <div class="flex items-center gap-2">
        <Button
          v-if="props.resultsManager.unacknowledgedCount.value > 0"
          variant="outline"
          size="sm"
          :disabled="!canAcknowledge"
          :title="canAcknowledge ? undefined : t('clinician.acknowledge_requires_consultation')"
          class="h-7 text-xs font-semibold gap-1.5 border-primary/30 text-primary hover:bg-primary/10 cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
          @click="canAcknowledge && props.resultsManager.acknowledgeAll()"
        >
          <CheckCheck class="size-3.5" />
          <span>{{ t("clinician.acknowledge_all", "Acknowledge All") }} ({{ props.resultsManager.unacknowledgedCount.value }})</span>
        </Button>

        <Button
          variant="ghost"
          size="sm"
          class="h-7 gap-1.5 text-xs text-muted-foreground hover:text-foreground cursor-pointer"
          @click="props.resultsManager.fetchResults(patientId ?? undefined)"
        >
          <RefreshCw class="size-3" :class="{ 'animate-spin': props.resultsManager.isResultsLoading.value }" />
          <span>{{ t("common.retry", "Refresh") }}</span>
        </Button>
      </div>
    </div>

    <!-- Filter & Search Toolbar (consistent with other clinician tabs) -->
    <div class="flex flex-wrap items-center justify-between gap-2">
      <!-- Category Segmented Filters -->
      <div class="flex items-center gap-1.5">
        <Button
          type="button"
          variant="outline"
          size="sm"
          class="h-7 text-xs font-medium px-2.5 cursor-pointer"
          :class="activeCategoryFilter === 'all' ? 'bg-muted font-semibold text-foreground' : 'text-muted-foreground'"
          @click="activeCategoryFilter = 'all'"
        >
          <span>{{ t("common.all", "All Results") }}</span>
          <span class="ml-1 font-mono text-[10px] text-muted-foreground">({{ props.resultsManager.totalResultsCount.value }})</span>
        </Button>

        <Button
          type="button"
          variant="outline"
          size="sm"
          class="h-7 text-xs font-medium px-2.5 cursor-pointer gap-1"
          :class="activeCategoryFilter === 'lab' ? 'bg-muted font-semibold text-foreground' : 'text-muted-foreground'"
          @click="activeCategoryFilter = 'lab'"
        >
          <FlaskConical class="size-3 text-sky-500" />
          <span>Laboratory</span>
          <span class="font-mono text-[10px] text-muted-foreground">({{ props.resultsManager.labCount.value }})</span>
        </Button>

        <Button
          type="button"
          variant="outline"
          size="sm"
          class="h-7 text-xs font-medium px-2.5 cursor-pointer gap-1"
          :class="activeCategoryFilter === 'imaging' ? 'bg-muted font-semibold text-foreground' : 'text-muted-foreground'"
          @click="activeCategoryFilter = 'imaging'"
        >
          <Scan class="size-3 text-purple-500" />
          <span>Imaging / Radiology</span>
          <span class="font-mono text-[10px] text-muted-foreground">({{ props.resultsManager.imagingCount.value }})</span>
        </Button>
      </div>

      <!-- Search Filter -->
      <div class="relative min-w-[200px] max-w-xs flex-1 sm:flex-initial">
        <Search class="absolute left-2.5 top-2 size-3.5 text-muted-foreground pointer-events-none" />
        <Input
          v-model="searchQuery"
          type="search"
          :placeholder="t('clinician.search_results', 'Filter results...')"
          class="h-7 pl-8 text-xs bg-surface"
        />
      </div>
    </div>

    <!-- Loading Skeleton -->
    <div v-if="props.resultsManager.isResultsLoading.value" class="space-y-2.5">
      <div v-for="n in 3" :key="n" class="h-20 rounded-lg bg-card border border-border animate-pulse" />
    </div>

    <!-- Empty State -->
    <div
      v-else-if="filteredResults.length === 0"
      class="rounded-lg border border-border bg-card p-6 text-center text-xs text-muted-foreground space-y-1.5"
    >
      <FlaskConical class="size-7 mx-auto text-muted-foreground/40 stroke-1" />
      <p class="font-semibold text-foreground">{{ t("clinician.select_result_hint", "No diagnostic results available") }}</p>
      <p class="text-[11px]">Results will appear here as soon as laboratory or radiology reports are completed and verified.</p>
    </div>

    <!-- Results Cards List -->
    <div v-else class="space-y-2.5">
      <div
        v-for="result in filteredResults"
        :key="result.id"
        class="rounded-lg border border-border bg-card p-3 space-y-2 shadow-2xs"
      >
        <!-- Card Header -->
        <div class="flex flex-row items-center justify-between gap-2 pb-1.5 border-b border-border/50">
          <div class="flex items-center gap-2 min-w-0">
            <Badge
              :variant="result.category === 'imaging' ? 'info' : 'default'"
              class="uppercase text-[9px] font-mono px-1.5 py-0 shrink-0"
            >
              {{ result.category === 'imaging' ? (result.modality ? `RAD · ${result.modality.toUpperCase()}` : 'IMAGING') : 'LAB' }}
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
              <span>{{ t(`clinician.flag_${result.flag}`, result.flag) }}</span>
            </Badge>

            <!-- Acknowledge Button -->
            <Button
              v-if="!result.isAcknowledged"
              size="sm"
              variant="outline"
              :disabled="!canAcknowledge"
              :title="canAcknowledge ? undefined : t('clinician.acknowledge_requires_consultation')"
              class="h-6 gap-1 px-2 text-[10.5px] font-semibold text-primary border-primary/40 hover:bg-primary/10 cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
              @click="canAcknowledge && props.resultsManager.acknowledgeResult(result.id)"
            >
              <Lock v-if="!canAcknowledge" class="size-3" />
              <FileCheck v-else class="size-3" />
              <span>{{ t("clinician.acknowledge_result", "Acknowledge") }}</span>
            </Button>
            <span v-else class="inline-flex items-center gap-1 text-[10.5px] font-medium text-emerald-600 dark:text-emerald-400">
              <CheckCircle2 class="size-3.5" />
              {{ t("clinician.acknowledged", "Acknowledged") }}
            </span>
          </div>
        </div>

        <!-- Card Body -->
        <div class="space-y-2 text-xs pt-1">
          <!-- Measured Values / Reference Strip -->
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 text-xs bg-muted/20 p-2 rounded border border-border/50">
            <div>
              <span class="text-muted-foreground text-[10.5px] block font-medium">{{ t("clinician.value", "Result / Status") }}</span>
              <div class="flex items-baseline gap-1 mt-0.5">
                <span class="font-bold text-sm font-mono text-foreground">{{ result.value }}</span>
                <span v-if="result.unit" class="text-xs text-muted-foreground font-mono">{{ result.unit }}</span>
              </div>
            </div>
            <div>
              <span class="text-muted-foreground text-[10.5px] block font-medium">{{ t("clinician.reference", "Reference") }}</span>
              <span class="font-mono text-foreground font-medium text-xs mt-0.5 block">{{ result.referenceRange }}</span>
            </div>
            <div>
              <span class="text-muted-foreground text-[10.5px] block font-medium">{{ t("clinician.date", "Date & Staff") }}</span>
              <span class="font-mono text-muted-foreground text-[11px] mt-0.5 block truncate">
                {{ formatClinicalDate(result.performedAt) }} · {{ result.technicianName }}
              </span>
            </div>
          </div>

          <!-- Structured Interpretation / Findings -->
          <div v-if="result.interpretation" class="border-t border-border/60 pt-2 text-xs">
            <span class="text-muted-foreground text-[10.5px] block font-semibold">
              {{ result.category === 'imaging' ? t('clinician.imaging_findings_impression', 'Diagnostic Findings & Impression:') : t("clinician.impression", "Clinical Impression") + ':' }}
            </span>
            <div
              v-if="result.category === 'imaging'"
              class="text-foreground leading-relaxed mt-1 p-2.5 rounded bg-muted/20 border border-border/50 whitespace-pre-wrap font-mono text-[11px]"
            >
              {{ result.interpretation }}
            </div>
            <p v-else class="text-foreground leading-relaxed mt-0.5">{{ result.interpretation }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
