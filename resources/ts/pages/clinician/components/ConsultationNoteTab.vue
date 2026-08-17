/**
 * ConsultationNoteTab — SOAP Clinical Note & ICD-10 Diagnostic Engine (Volume 2.2 §6 / §7)
 * =========================================================================================
 * 2027 Modern Enterprise Clinical Workstation Edition:
 * - Elevated Structured SOAP Cards (Subjective, Objective, Assessment & ICD-10, Plan)
 * - Quick-Exam Templates & Normal Default finding chips
 * - Autocomplete ICD-10 Search with category tagging & primary/secondary diagnostic toggles
 * - Live Draft Persistence status & dirty/saved indicators
 * - One-click Treatment Plan templates
 */

<script setup lang="ts">
import {
  Activity,
  Check,
  CheckCircle2,
  ChevronDown,
  Clock,
  Eye,
  FileText,
  HeartPulse,
  Plus,
  RefreshCw,
  RotateCcw,
  Save,
  Search,
  Sparkles,
  Stethoscope,
  Trash2,
  X,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  COMMON_ICD10_CATALOG,
  type Icd10CatalogItem,
  useClinicianEncounter,
} from "../composables/useClinicianEncounter";

const props = withDefaults(
  defineProps<{
    encounter: ReturnType<typeof useClinicianEncounter>;
    clinicalMode?: "active" | "awaiting_start" | "triage_pending" | "read_only" | "completed";
  }>(),
  {
    clinicalMode: "active",
  }
);

const { t } = useI18n({ useScope: "global" });

const icdSearchQuery = ref("");
const showIcdDropdown = ref(false);

const filteredIcd10 = computed(() => {
  const q = icdSearchQuery.value.trim().toLowerCase();
  if (!q) return COMMON_ICD10_CATALOG.slice(0, 8);
  return COMMON_ICD10_CATALOG.filter(
    (item) => item.code.toLowerCase().includes(q) || item.name.toLowerCase().includes(q)
  );
});

function selectIcd10Item(item: Icd10CatalogItem) {
  if (props.clinicalMode !== "active") return;
  props.encounter.addDiagnosis(item);
  icdSearchQuery.value = "";
  showIcdDropdown.value = false;
}

function formatSavedTime(dateStr: string | null | undefined): string {
  if (!dateStr) return "";
  try {
    const d = new Date(dateStr);
    return d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  } catch {
    return "";
  }
}

// Quick Physical Exam Templates
const EXAM_TEMPLATES = [
  "General: Alert, oriented x3, no acute distress.",
  "HEENT: Normocephalic, PERRLA, pink conjunctiva, non-icteric sclera.",
  "Chest/Lungs: Clear to auscultation bilaterally, no wheezing or crackles.",
  "CVS: S1, S2 heard, regular rate and rhythm, no murmurs.",
  "Abdomen: Soft, non-tender, non-distended, normal bowel sounds.",
];

function appendExamTemplate(text: string) {
  if (props.clinicalMode !== "active") return;
  const current = props.encounter.physicalExam.value.trim();
  if (current) {
    props.encounter.physicalExam.value = `${current}\n${text}`;
  } else {
    props.encounter.physicalExam.value = text;
  }
}

// Quick Treatment Plan Suggestions
const PLAN_SUGGESTIONS = [
  "Advised adequate bed rest and high fluid intake.",
  "Ordered baseline diagnostic workup.",
  "Prescribed standard course of pharmacotherapy.",
  "Instructed to return immediately if symptoms worsen or fever persists > 48h.",
  "Follow-up scheduled in 3 days for clinical review.",
];

function appendPlanSuggestion(text: string) {
  if (props.clinicalMode !== "active") return;
  const current = props.encounter.plan.value.trim();
  if (current) {
    props.encounter.plan.value = `${current}\n• ${text}`;
  } else {
    props.encounter.plan.value = `• ${text}`;
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
      class="rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-200 flex items-center gap-2"
    >
      <div class="flex items-center gap-2">
        <Clock class="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
        <span class="text-xs leading-tight">
          <strong class="font-semibold">{{ t("clinician.triage_pending_title") }}:</strong>
          <span class="text-amber-800/90 dark:text-amber-300/90 ml-1">{{ t("clinician.triage_pending_desc") }}</span>
        </span>
      </div>
    </div>

    <div
      v-else-if="props.clinicalMode === 'read_only'"
      class="rounded-md border border-border/80 bg-muted/40 px-3 py-2 text-xs text-muted-foreground flex items-center gap-2"
    >
      <Eye class="size-4 shrink-0 text-primary" />
      <span class="text-xs leading-tight">
        <strong class="font-semibold text-foreground">{{ t("clinician.read_only_review_title") }}:</strong>
        <span class="ml-1">{{ t("clinician.read_only_review_desc") }}</span>
      </span>
    </div>

    <div
      v-else-if="props.clinicalMode === 'completed'"
      class="rounded-md border border-border bg-secondary/40 px-3 py-2 text-xs text-muted-foreground flex items-center gap-2"
    >
      <CheckCircle2 class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
      <span class="text-xs leading-tight">
        <strong class="font-semibold text-foreground">{{ t("clinician.encounter_completed_title") }}:</strong>
        <span class="ml-1">{{ t("clinician.encounter_completed_desc") }}</span>
      </span>
    </div>

    <!-- ============================================================
         CARD 1: S · SUBJECTIVE (Chief Complaint, HPI, ROS)
         ============================================================ -->
    <section class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-3">
      <div class="flex items-center justify-between border-b border-border/80 pb-2">
        <div class="flex items-center gap-2">
          <div class="flex size-6 items-center justify-center rounded-md bg-primary/10 text-primary">
            <FileText class="size-3.5" aria-hidden="true" />
          </div>
          <h3 class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
            <span>S · {{ t("clinician.subjective") }}</span>
            <Badge variant="outline" class="text-[9px] font-mono px-1 py-0 uppercase">History</Badge>
          </h3>
        </div>

        <!-- Auto-save / Draft Status indicator -->
        <div class="flex items-center gap-2 text-xs">
          <span
            v-if="encounter.isSavingNote.value"
            class="inline-flex items-center gap-1 text-muted-foreground text-[11px] font-medium"
          >
            <RefreshCw class="size-3 animate-spin text-primary" />
            <span>{{ t("clinician.draft_saving") }}</span>
          </span>

          <span
            v-else-if="encounter.isDraftDirty.value"
            class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400 text-[11px] font-medium"
          >
            <span class="size-1.5 rounded-full bg-amber-500 animate-pulse" />
            <span>{{ t("clinician.draft_unsaved") }}</span>
          </span>

          <span
            v-else-if="encounter.lastSavedAt.value"
            class="inline-flex items-center gap-1 text-muted-foreground font-mono text-[10.5px]"
          >
            <Check class="size-3 text-emerald-600 dark:text-emerald-400" />
            <span>{{ t("clinician.draft_saved") }} {{ formatSavedTime(encounter.lastSavedAt.value) }}</span>
          </span>

          <button
            v-if="encounter.isDraftDirty.value"
            type="button"
            class="inline-flex items-center gap-1 text-[10.5px] text-muted-foreground hover:text-critical cursor-pointer ml-1 underline decoration-dotted"
            :title="t('clinician.discard_draft')"
            @click="encounter.discardDraft"
          >
            <RotateCcw class="size-2.5" />
            <span>{{ t("clinician.discard_draft") }}</span>
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
        <!-- Chief Complaint (Full Width on Mobile) -->
        <div class="space-y-1.5 md:col-span-2">
          <Label for="chief-complaint" required class="text-xs font-semibold text-foreground">
            {{ t("clinician.chief_complaint") }}
          </Label>
          <Input
            id="chief-complaint"
            v-model="encounter.chiefComplaint.value"
            type="text"
            class="h-8 text-xs font-medium disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="props.clinicalMode !== 'active'"
            :placeholder="t('clinician.chief_complaint_placeholder')"
          />
        </div>

        <!-- History of Present Illness (HPI) -->
        <div class="space-y-1.5">
          <Label for="hpi" class="text-xs font-semibold text-foreground">
            {{ t("clinician.history_present_illness") }}
          </Label>
          <Textarea
            id="hpi"
            v-model="encounter.historyOfPresentIllness.value"
            rows="3"
            class="text-xs leading-relaxed disabled:opacity-60 disabled:cursor-not-allowed resize-none"
            :disabled="props.clinicalMode !== 'active'"
            :placeholder="t('clinician.history_present_illness_placeholder')"
          />
        </div>

        <!-- Review of Systems (ROS) -->
        <div class="space-y-1.5">
          <Label for="ros" class="text-xs font-semibold text-foreground">
            {{ t("clinician.review_of_systems") }}
          </Label>
          <Textarea
            id="ros"
            v-model="encounter.reviewOfSystems.value"
            rows="3"
            class="text-xs leading-relaxed disabled:opacity-60 disabled:cursor-not-allowed resize-none"
            :disabled="props.clinicalMode !== 'active'"
            :placeholder="t('clinician.review_of_systems_placeholder')"
          />
        </div>
      </div>
    </section>

    <!-- ============================================================
         CARD 2: O · OBJECTIVE (Physical Examination)
         ============================================================ -->
    <section class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-2.5">
      <div class="flex items-center justify-between border-b border-border/80 pb-2">
        <div class="flex items-center gap-2">
          <div class="flex size-6 items-center justify-center rounded-md bg-emerald-500/10 text-emerald-600">
            <Activity class="size-3.5" aria-hidden="true" />
          </div>
          <h3 class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
            <span>O · {{ t("clinician.objective") }}</span>
            <Badge variant="outline" class="text-[9px] font-mono px-1 py-0 uppercase">Examination</Badge>
          </h3>
        </div>

        <span class="text-[11px] text-muted-foreground font-mono">Physical Findings</span>
      </div>

      <div class="space-y-2 text-xs">
        <div class="space-y-1.5">
          <div class="flex items-center justify-between">
            <Label for="physical-exam" class="text-xs font-semibold text-foreground">
              {{ t("clinician.physical_exam") }}
            </Label>
            <!-- Quick Exam Template Badges -->
            <div class="flex items-center gap-1 flex-wrap">
              <span class="text-[10px] text-muted-foreground font-medium mr-1">Normal Defaults:</span>
              <button
                v-for="tpl in EXAM_TEMPLATES"
                :key="tpl"
                type="button"
                class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded border border-border/70 bg-secondary/50 hover:bg-secondary text-[10px] text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                :disabled="props.clinicalMode !== 'active'"
                @click="appendExamTemplate(tpl)"
              >
                <Plus class="size-2.5 text-primary" />
                <span>{{ tpl.split(':')[0] }}</span>
              </button>
            </div>
          </div>

          <Textarea
            id="physical-exam"
            v-model="encounter.physicalExam.value"
            rows="3"
            class="text-xs leading-relaxed disabled:opacity-60 disabled:cursor-not-allowed resize-none"
            :disabled="props.clinicalMode !== 'active'"
            :placeholder="t('clinician.physical_exam_placeholder')"
          />
        </div>
      </div>
    </section>

    <!-- ============================================================
         CARD 3: A · ASSESSMENT & ICD-10 DIAGNOSES
         ============================================================ -->
    <section class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-3">
      <div class="flex items-center justify-between border-b border-border/80 pb-2">
        <div class="flex items-center gap-2">
          <div class="flex size-6 items-center justify-center rounded-md bg-blue-500/10 text-blue-600">
            <Stethoscope class="size-3.5" aria-hidden="true" />
          </div>
          <h3 class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
            <span>A · {{ t("clinician.assessment") }} & {{ t("clinician.diagnoses") }}</span>
            <Badge variant="outline" class="text-[9px] font-mono px-1 py-0 uppercase">ICD-10</Badge>
          </h3>
        </div>
      </div>

      <div class="space-y-3 text-xs">
        <!-- ICD-10 Search & Quick Picker -->
        <div class="space-y-2">
          <Label required class="text-xs font-semibold text-foreground">
            {{ t("clinician.diagnoses") }}
          </Label>

          <div class="relative">
            <div class="relative">
              <Search class="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground" />
              <Input
                v-model="icdSearchQuery"
                type="search"
                class="pl-8 h-8 text-xs font-mono disabled:opacity-60 disabled:cursor-not-allowed"
                :disabled="props.clinicalMode !== 'active'"
                :placeholder="t('clinician.search_icd10')"
                @focus="showIcdDropdown = true"
              />
            </div>

            <!-- Autocomplete Dropdown -->
            <div
              v-if="showIcdDropdown && filteredIcd10.length > 0 && props.clinicalMode === 'active'"
              class="absolute z-50 mt-1 w-full rounded-md border border-border bg-popover p-1 shadow-lg max-h-56 overflow-y-auto"
            >
              <div
                v-for="item in filteredIcd10"
                :key="item.code"
                class="flex items-center justify-between gap-2 rounded px-2.5 py-1.5 text-xs hover:bg-accent cursor-pointer transition-colors"
                @mousedown.prevent="selectIcd10Item(item)"
              >
                <div class="flex items-center gap-2 min-w-0">
                  <span class="font-mono font-bold text-primary text-[11px] bg-primary/10 px-1.5 py-0.5 rounded shrink-0">
                    {{ item.code }}
                  </span>
                  <span class="truncate font-medium text-foreground text-[12px]">{{ item.name }}</span>
                </div>
                <span class="text-[10px] text-muted-foreground shrink-0 uppercase tracking-wider font-mono">
                  {{ item.category }}
                </span>
              </div>
            </div>
          </div>

          <!-- Quick Picks Pills -->
          <div class="flex items-center gap-1.5 flex-wrap pt-0.5">
            <span class="text-[10.5px] text-muted-foreground font-semibold uppercase tracking-wider mr-1">
              {{ t("common.suggested", "Quick picks:") }}
            </span>
            <button
              v-for="item in COMMON_ICD10_CATALOG.slice(0, 6)"
              :key="item.code"
              type="button"
              class="inline-flex items-center gap-1 rounded-full border border-border bg-secondary/50 px-2 py-0.5 text-[11px] text-foreground transition-all disabled:opacity-50 disabled:cursor-not-allowed"
              :class="props.clinicalMode === 'active' ? 'hover:border-primary/40 hover:bg-secondary cursor-pointer' : ''"
              :disabled="props.clinicalMode !== 'active'"
              @click="encounter.addDiagnosis(item)"
            >
              <Plus class="size-2.5 text-primary" />
              <span class="font-bold text-primary font-mono text-[10px]">{{ item.code }}</span>
              <span>{{ item.name.split(",")[0] }}</span>
            </button>
          </div>
        </div>

        <!-- Selected Diagnoses Table / List -->
        <div class="rounded-lg border border-border bg-surface overflow-hidden">
          <div v-if="encounter.diagnoses.value.length === 0" class="p-3 text-center text-xs text-muted-foreground italic">
            {{ t("clinician.no_diagnoses_added") }}
          </div>
          <ul v-else class="divide-y divide-border/60">
            <li
              v-for="(diag, idx) in encounter.diagnoses.value"
              :key="diag.code"
              class="p-2.5 flex items-center justify-between gap-3 text-xs"
            >
              <div class="flex items-center gap-2.5 min-w-0">
                <span class="font-mono font-bold text-primary bg-primary/10 px-2 py-0.5 rounded text-[11px] shrink-0">
                  {{ diag.code }}
                </span>
                <span class="font-medium text-foreground text-[12px] truncate">
                  {{ diag.name }}
                </span>
              </div>

              <div class="flex items-center gap-2 shrink-0">
                <!-- Primary / Secondary Switch -->
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  class="h-6.5 px-2 text-[10.5px] font-medium cursor-pointer"
                  :class="[
                    diag.isPrimary
                      ? 'bg-primary/15 text-primary font-bold hover:bg-primary/20'
                      : 'text-muted-foreground hover:text-foreground',
                  ]"
                  :disabled="props.clinicalMode !== 'active'"
                  @click="encounter.setPrimaryDiagnosis(idx)"
                >
                  {{ diag.isPrimary ? t("clinician.primary_diagnosis") : t("clinician.secondary_diagnosis") }}
                </Button>

                <!-- Certainty Selector (Provisional vs Confirmed) -->
                <select
                  v-model="diag.certainty"
                  class="h-6.5 rounded border border-border bg-background px-2 text-[10.5px] font-medium text-foreground disabled:opacity-60 disabled:cursor-not-allowed"
                  :disabled="props.clinicalMode !== 'active'"
                >
                  <option value="provisional">{{ t("clinician.provisional") }}</option>
                  <option value="confirmed">{{ t("clinician.confirmed") }}</option>
                </select>

                <!-- Delete -->
                <button
                  v-if="props.clinicalMode === 'active'"
                  type="button"
                  class="p-1 text-muted-foreground hover:text-critical transition-colors cursor-pointer"
                  :title="t('clinician.remove_diagnosis')"
                  @click="encounter.removeDiagnosis(idx)"
                >
                  <Trash2 class="size-3.5" />
                </button>
              </div>
            </li>
          </ul>
        </div>

        <!-- Clinical Impression / Reasoning -->
        <div class="space-y-1.5">
          <Label for="assessment-text" class="text-xs font-semibold text-foreground">
            {{ t("clinician.assessment") }} (Clinical Impression)
          </Label>
          <Textarea
            id="assessment-text"
            v-model="encounter.assessment.value"
            rows="2"
            class="text-xs leading-relaxed disabled:opacity-60 disabled:cursor-not-allowed resize-none"
            :disabled="props.clinicalMode !== 'active'"
            :placeholder="t('clinician.assessment_placeholder')"
          />
        </div>
      </div>
    </section>

    <!-- ============================================================
         CARD 4: P · PLAN & MANAGEMENT
         ============================================================ -->
    <section class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-3">
      <div class="flex items-center justify-between border-b border-border/80 pb-2">
        <div class="flex items-center gap-2">
          <div class="flex size-6 items-center justify-center rounded-md bg-purple-500/10 text-purple-600">
            <HeartPulse class="size-3.5" aria-hidden="true" />
          </div>
          <h3 class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
            <span>P · {{ t("clinician.plan") }}</span>
            <Badge variant="outline" class="text-[9px] font-mono px-1 py-0 uppercase">Management</Badge>
          </h3>
        </div>

        <!-- Quick Plan Suggestion Buttons -->
        <div class="flex items-center gap-1 flex-wrap">
          <span class="text-[10px] text-muted-foreground font-medium mr-1">Quick Plan:</span>
          <button
            v-for="sug in PLAN_SUGGESTIONS.slice(0, 3)"
            :key="sug"
            type="button"
            class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded border border-border/70 bg-secondary/50 hover:bg-secondary text-[10px] text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
            :disabled="props.clinicalMode !== 'active'"
            @click="appendPlanSuggestion(sug)"
          >
            <Plus class="size-2.5 text-primary" />
            <span>{{ sug.split(' ')[1] }}...</span>
          </button>
        </div>
      </div>

      <div class="space-y-1.5 text-xs">
        <Label for="clinical-plan" class="text-xs font-semibold text-foreground">
          {{ t("clinician.plan") }} (Management, Advice & Follow-up)
        </Label>
        <Textarea
          id="clinical-plan"
          v-model="encounter.plan.value"
          rows="3"
          class="text-xs leading-relaxed disabled:opacity-60 disabled:cursor-not-allowed resize-none"
          :disabled="props.clinicalMode !== 'active'"
          :placeholder="t('clinician.plan_placeholder')"
        />
      </div>
    </section>
  </div>
</template>
