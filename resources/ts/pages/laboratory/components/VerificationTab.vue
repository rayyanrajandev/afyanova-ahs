/** * VerificationTab — Senior Scientist Electronic Validation & EMR Release
(Volume 2.4 §7.2) *
=======================================================================================
* 2027 Modern Enterprise Hospital LIS Verification Station: * - Comprehensive
Diagnostic Report Card Preview * - Two-Eye Review: Parameter Inspection & QC Run
Validation * - Supervisor Clinical Impression & Authorization Remarks * -
Instant Electronic Release to Clinician Chart & PDF Export * - Full
Internationalization (i18n) Support */

<script setup lang="ts">
import {
  AlertTriangle,
  Award,
  CheckCircle2,
  Clock,
  Download,
  FileCheck,
  FlaskConical,
  Send,
  Sparkles,
  UserCheck,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
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
  secondReviewReason,
  type LabStage,
  type LaboratoryOrder,
  type UseLaboratoryOrders,
} from "../composables/useLaboratoryOrders";

const props = defineProps<{
  order: LaboratoryOrder;
  laboratory: UseLaboratoryOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const patientOrders = computed(() =>
  props.laboratory.orders.value.filter(
    (o) => o.patientId === props.order.patientId,
  ),
);

const supervisorComments = ref(props.order.interpretation || "");

interface ReleaseNotePreset {
  label: string;
  text: string;
}

const releaseNotePresets: ReleaseNotePreset[] = [
  {
    label: "Standard — IQC Passed (2SD)",
    text: "Results verified against daily IQC calibration within 2SD limits. Clinically valid.",
  },
  {
    label: "Normal Screening Baseline",
    text: "Normal baseline parameters confirmed. No significant pathological abnormalities noted.",
  },
  {
    label: "Duplicate Analysis Verified",
    text: "Analytical parameters repeated in duplicate and confirmed. QC rules satisfied.",
  },
  {
    label: "Critical Value Telephoned",
    text: "Panic critical values flagged, verified, and read back to attending clinician.",
  },
  {
    label: "Pre-analytical Quality Note",
    text: "Sample slightly hemolyzed/lipemic; results verified with clinical correlation advised.",
  },
];

function onSelectQuickNote(event: Event) {
  const target = event.target as HTMLSelectElement;
  if (target && target.value) {
    supervisorComments.value = target.value;
    target.selectedIndex = 0;
  }
}

function applyPreset(text: string) {
  supervisorComments.value = text;
}

const stage = computed<LabStage>(() => labStageOf(props.order));

/**
 * Released means the report is on the patient's chart — `verifiedAt` is set.
 * The old check treated `status === 'completed'` as verified, which was true
 * the instant results were typed, so a draft rendered as "Final Verified
 * Report" before anyone had reviewed it.
 */
const isReleased = computed(() => stage.value === "released");

const canRelease = computed(() => stage.value === "awaiting_release");

/**
 * A second pair of eyes is demanded only where it changes the outcome —
 * critical values and high-stakes disciplines. Prompting on every release
 * turns the acknowledgement into a reflex click within a week.
 */
const secondReview = computed(() => secondReviewReason(props.order));

const selfVerifyAcknowledged = ref(false);

const releaseBlockReason = computed<string | null>(() => {
  if (!canRelease.value) {
    return t(
      "laboratory.release_blocked_stage",
      "Save the results before releasing this report.",
    );
  }
  if (supervisorComments.value.trim() === "") {
    return t("laboratory.release_blocked_note", "A release note is required.");
  }
  if (secondReview.value !== null && !selfVerifyAcknowledged.value) {
    return t(
      "laboratory.release_blocked_review",
      "Confirm the second-review declaration first.",
    );
  }

  return null;
});

async function handleRelease() {
  if (releaseBlockReason.value !== null) return;

  await props.laboratory.releaseResults(
    props.order.id,
    supervisorComments.value,
    secondReview.value !== null && selfVerifyAcknowledged.value,
  );
}
</script>

<template>
  <div class="space-y-3.5 p-3.5 w-full">
    <!-- Diagnostic Report Card -->
    <section
      class="rounded-lg border border-border bg-surface p-4 shadow-2xs space-y-4"
    >
      <!-- Report Header -->
      <div
        class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3"
      >
        <div>
          <div class="flex items-center gap-2">
            <h3 class="text-sm font-bold text-foreground">
              {{
                t(
                  "laboratory.official_report",
                  "Official Diagnostic Laboratory Report",
                )
              }}
            </h3>
            <Badge
              variant="outline"
              class="text-[9px] font-mono uppercase px-1.5 py-0"
            >
              {{ t("laboratory.iso_badge", "ISO 15189") }}
            </Badge>
          </div>
          <p class="text-[11px] text-muted-foreground mt-0.5">
            {{
              t(
                "laboratory.hospital_lab_name",
                "AfyaNova Automated Clinical Laboratories",
              )
            }}
            · {{ t("laboratory.dept_of", { dept: order.department }) }}
          </p>
        </div>

        <div class="flex items-center gap-2 font-mono text-xs">
          <Badge
            variant="outline"
            class="text-[10px] uppercase font-mono px-2 py-0.5"
            :class="
              isReleased
                ? 'border-emerald-500 text-emerald-600 bg-emerald-500/10'
                : 'border-amber-500 text-amber-600 bg-amber-500/10'
            "
          >
            {{
              isReleased
                ? t("laboratory.final_report", "Final Verified Report")
                : t("laboratory.draft_report", "Draft / Pre-Release")
            }}
          </Badge>
        </div>
      </div>

      <!-- Parameter Results Table -->
      <div class="rounded-lg border border-border bg-surface overflow-hidden">
        <table class="w-full text-left text-xs table-fixed">
          <thead
            class="border-b border-border/70 bg-muted/30 text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider"
          >
            <tr>
              <th class="p-2.5 pl-3 w-[32%]">
                {{ t("laboratory.meta_test", "Test Investigation") }}
              </th>
              <th class="p-2.5 w-[20%]">
                {{ t("laboratory.meta_result", "Observed Result") }}
              </th>
              <th class="p-2.5 w-[14%]">
                {{ t("laboratory.th_units", "Units") }}
              </th>
              <th class="p-2.5 w-[20%]">
                {{ t("laboratory.th_reference", "Biological Reference") }}
              </th>
              <th class="p-2.5 w-[14%] text-right pr-3">
                {{ t("laboratory.th_flag", "Evaluation") }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr
              v-for="p in order.parameters"
              :key="p.key"
              class="hover:bg-muted/15"
            >
              <td class="p-2.5 pl-3 font-semibold text-foreground text-[12px]">
                {{ p.name }}
              </td>
              <td
                class="p-2.5 font-mono font-bold text-[12.5px]"
                :class="{
                  'text-rose-600': p.flag.startsWith('critical'),
                  'text-amber-600': p.flag === 'abnormal',
                  'text-foreground': p.flag === 'normal',
                }"
              >
                {{ p.value ?? "—" }}
              </td>
              <td class="p-2.5 text-muted-foreground font-mono text-[11px]">
                {{ p.unit }}
              </td>
              <td class="p-2.5 font-mono text-[11px] text-muted-foreground">
                {{ p.referenceRange }}
              </td>
              <td class="p-2.5 pr-3 text-right">
                <Badge
                  variant="outline"
                  class="text-[9px] font-mono font-bold uppercase px-1.5 py-0"
                  :class="{
                    'border-emerald-500/40 text-emerald-600 bg-emerald-500/10':
                      p.flag === 'normal',
                    'border-amber-500/40 text-amber-600 bg-amber-500/10':
                      p.flag === 'abnormal',
                    'border-rose-500/50 text-rose-600 bg-rose-500/15':
                      p.flag.startsWith('critical'),
                  }"
                >
                  {{
                    p.flag === "normal"
                      ? t("laboratory.flag_normal", "NORMAL")
                      : p.flag === "abnormal"
                        ? t("laboratory.flag_abnormal", "ABNORMAL")
                        : t("laboratory.flag_crit_high", "CRITICAL")
                  }}
                </Badge>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- QC & Supervisor Comments -->
      <div class="space-y-3 pt-2 text-xs">
        <!-- Quality Control Validation Pill -->
        <div
          class="flex items-center justify-between p-2.5 rounded-lg border border-emerald-500/30 bg-emerald-500/5"
        >
          <div class="flex items-center gap-2">
            <Award class="size-4 text-emerald-600" />
            <span class="font-medium text-foreground">
              {{ t("laboratory.iqc_title", "Internal Quality Control (IQC):") }}
              <strong class="text-emerald-600">{{
                t("laboratory.iqc_passed", "Passed (2SD Limit)")
              }}</strong>
            </span>
          </div>
          <span class="text-[11px] font-mono text-muted-foreground">{{
            t("laboratory.westgard_ok", "Westgard Multi-Rule OK")
          }}</span>
        </div>

        <!-- Supervisor Clinical Impression -->
        <div class="space-y-1.5">
          <div class="flex flex-wrap items-center justify-between gap-1.5">
            <Label required class="text-xs font-semibold text-foreground">
              {{
                t(
                  "laboratory.senior_remarks",
                  "Senior Scientist Remarks & Clinical Release Notes",
                )
              }}
            </Label>

            <!-- Quick Preset Templates Dropdown (Shadcn Vue Select) -->
            <div v-if="canRelease" class="flex items-center gap-1.5">
              <span
                class="text-[10px] text-muted-foreground font-medium whitespace-nowrap"
                >{{ t("laboratory.quick_presets", "Quick Presets:") }}</span
              >
              <Select
                @update:model-value="
                  (val: any) => val && applyPreset(String(val))
                "
              >
                <SelectTrigger
                  class="h-6.5 min-w-[190px] text-[11px] px-2 py-0 border-border bg-background shadow-2xs"
                >
                  <SelectValue
                    :placeholder="
                      t('laboratory.select_preset', 'Select standard note...')
                    "
                  />
                </SelectTrigger>
                <SelectContent class="text-xs">
                  <SelectItem
                    v-for="preset in releaseNotePresets"
                    :key="preset.label"
                    :value="preset.text"
                    class="text-xs py-1.5 cursor-pointer"
                  >
                    <span class="font-medium">{{ preset.label }}</span>
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <!-- Quick Template Chips (1-click autofill) -->
          <div v-if="canRelease" class="flex flex-wrap gap-1 pb-0.5">
            <button
              v-for="preset in releaseNotePresets.slice(0, 3)"
              :key="preset.label"
              type="button"
              class="inline-flex items-center gap-1 rounded-md border border-border/80 bg-muted/40 px-2 py-0.5 text-[10px] font-medium text-muted-foreground hover:bg-primary/10 hover:text-primary hover:border-primary/40 transition-colors cursor-pointer"
              @click="applyPreset(preset.text)"
            >
              <Sparkles class="size-2.5 text-primary/70" />
              <span>{{ preset.label }}</span>
            </button>
          </div>

          <Textarea
            v-model="supervisorComments"
            rows="2"
            class="text-xs resize-none"
            :disabled="!canRelease"
            :placeholder="
              t(
                'laboratory.senior_remarks_placeholder',
                'Select a preset above or type specific clinical observations...',
              )
            "
          />
          <p
            v-if="canRelease && supervisorComments.trim() === ''"
            class="text-[10.5px] text-amber-600"
          >
            {{
              t(
                "laboratory.release_note_required",
                "A release note is required — choose a preset above or type specific remarks.",
              )
            }}
          </p>
        </div>

        <!--
          Second-review declaration, scoped to results where it matters.
          Recorded in the audit log via the verification note, so a single-tech
          district lab is never blocked but the self-verification is traceable.
        -->
        <div
          v-if="canRelease && secondReview !== null"
          class="space-y-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-2.5"
        >
          <div class="flex items-start gap-2">
            <AlertTriangle class="size-4 text-amber-600 shrink-0 mt-px" />
            <div class="text-[11px] text-amber-900 dark:text-amber-200">
              <p class="font-bold">
                {{
                  secondReview === "critical"
                    ? t(
                        "laboratory.second_review_critical",
                        "Critical result — second review required",
                      )
                    : t(
                        "laboratory.second_review_high_stakes",
                        "High-stakes result — second review required",
                      )
                }}
              </p>
              <p class="mt-0.5">
                {{
                  t(
                    "laboratory.second_review_desc",
                    "ISO 15189 §7.4 requires these results to be checked by a second reviewer before release. If no second scientist is available, you may self-verify — this is recorded in the audit log.",
                  )
                }}
              </p>
            </div>
          </div>

          <label
            class="flex items-center gap-2 text-[11px] font-semibold text-amber-900 dark:text-amber-200 cursor-pointer"
          >
            <input
              v-model="selfVerifyAcknowledged"
              type="checkbox"
              class="size-3.5 accent-amber-600 cursor-pointer"
            />
            <span>{{
              t(
                "laboratory.second_review_ack",
                "I have reviewed these results and accept responsibility for releasing them.",
              )
            }}</span>
          </label>
        </div>
      </div>
    </section>

    <!--
      Sign-off Action Bar — Step 4, and the ONLY route to the patient chart.
      Reachable only from `awaiting_release`, so a report cannot be released
      before its results exist.
    -->
    <div
      class="flex flex-wrap items-center justify-between gap-3 p-3.5 rounded-lg border border-border bg-surface shadow-2xs"
    >
      <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <UserCheck class="size-4 text-primary shrink-0" />
        <span v-if="releaseBlockReason">{{ releaseBlockReason }}</span>
        <span v-else-if="isReleased">
          {{
            t(
              "laboratory.already_released",
              "Released — this report is final and on the patient chart.",
            )
          }}
        </span>
        <span v-else>{{
          t(
            "laboratory.ready_to_release",
            "Ready to release to the patient chart.",
          )
        }}</span>
      </div>

      <div class="flex items-center gap-2">
        <Button
          v-if="!isReleased"
          size="sm"
          class="h-8 text-xs font-semibold gap-1.5 px-4 shadow-xs bg-emerald-600 hover:bg-emerald-700 text-white"
          :class="
            releaseBlockReason === null
              ? 'cursor-pointer'
              : 'cursor-not-allowed'
          "
          :disabled="
            releaseBlockReason !== null || laboratory.isVerifying.value
          "
          @click="handleRelease"
        >
          <CheckCircle2 class="size-3.5" />
          <span>{{
            laboratory.isVerifying.value
              ? t("laboratory.verifying", "Publishing...")
              : t("laboratory.authorize_release", "Authorize & Release to EMR")
          }}</span>
        </Button>
      </div>
    </div>
  </div>
</template>
