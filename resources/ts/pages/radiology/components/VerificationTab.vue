/** * VerificationTab — Diagnostic Imaging Verification & Chart Release (2027
Standard) *
===================================================================================
* - Radiologist Two-Person Quality Gate * - PACS DICOM Review Viewport (Key
Frames & Measurements Review) * - Findings & Impression Full Review Card * -
Release Note Presets (ISO 15189 / ACR Compliant) * - Official A4 Diagnostic
Report Print Launcher (clinicalPrintEngine) */

<script setup lang="ts">
import {
  Check,
  CheckCircle2,
  FileCheck2,
  FileText,
  Scan,
  ShieldCheck,
  Sparkles,
  Users,
} from "lucide-vue-next";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type {
  RadiologyOrder,
  UseRadiologyOrders,
} from "../composables/useRadiologyOrders";
import RadiologyPacsViewer from "./RadiologyPacsViewer.vue";

const props = defineProps<{
  order: RadiologyOrder;
  radiology: UseRadiologyOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const note = ref("");

interface VerificationPreset {
  label: string;
  text: string;
}

const VERIFICATION_PRESETS: VerificationPreset[] = [
  {
    label: "Standard Unremarkable Release",
    text: "Images reviewed and findings verified. Unremarkable examination corresponding with normal anatomical limits.",
  },
  {
    label: "Clinically Correlated",
    text: "Diagnostic findings confirmed. Clinical correlation with attending physician recommended.",
  },
  {
    label: "Follow-up Scan Recommended",
    text: "Findings verified. Follow-up imaging recommended in 4-6 weeks to assess interval resolution.",
  },
  {
    label: "Critical Finding Communicated",
    text: "Significant acute finding identified and directly read back to the ordering clinician.",
  },
];

watch(
  () => props.order.id,
  () => {
    note.value = props.order.verificationNote ?? "";
  },
  { immediate: true },
);

const isVerified = computed(() => Boolean(props.order.verifiedAt));
const hasReport = computed(
  () =>
    Boolean(props.order.reportSummary) ||
    props.order.status === "completed" ||
    isVerified.value,
);

function applyPreset(p: VerificationPreset) {
  note.value = p.text;
}

async function verify() {
  const ok = await props.radiology.verifyReport(
    props.order.id,
    note.value.trim(),
  );
  if (ok) note.value = "";
}
</script>

<template>
  <div class="space-y-4 p-3.5 w-full">
    <!-- Header Section -->
    <div class="flex items-center justify-between border-b border-border pb-3">
      <div class="flex items-center gap-2">
        <ShieldCheck class="size-4 text-emerald-600 dark:text-emerald-400" />
        <h3 class="text-sm font-bold text-foreground">
          {{
            t(
              "radiology.verification_title",
              "Radiologist Authorization & Official Release",
            )
          }}
        </h3>
      </div>

      <div class="flex items-center gap-2">
        <Badge
          variant="outline"
          class="text-[10px] uppercase font-mono px-2 py-0.5"
          :class="
            isVerified
              ? 'border-emerald-500 text-emerald-600 bg-emerald-500/10'
              : 'border-amber-500 text-amber-600 bg-amber-500/10'
          "
        >
          {{
            isVerified
              ? t("radiology.final_released", "Final Verified Report")
              : t("radiology.awaiting_verification", "Draft / Pre-Release")
          }}
        </Badge>
      </div>
    </div>

    <!-- State: Not yet completed by radiographer -->
    <div
      v-if="!hasReport"
      class="rounded-lg border border-border bg-surface p-6 text-center text-xs space-y-2 text-muted-foreground shadow-2xs"
    >
      <FileText class="size-8 mx-auto text-muted-foreground/40 stroke-1" />
      <p class="font-semibold text-foreground">
        {{
          t(
            "radiology.nothing_to_verify",
            "No report submitted for verification yet",
          )
        }}
      </p>
      <p class="text-[11px] max-w-md mx-auto">
        {{
          t(
            "radiology.nothing_to_verify_desc",
            "The radiographer must complete the examination and submit findings on the Report tab before the report can be verified and released.",
          )
        }}
      </p>
    </div>

    <!-- State: Ready for verification or Released -->
    <template v-else>
      <!-- Two-Person Rule Callout (When unverified) -->
      <div
        v-if="!isVerified"
        class="flex items-center gap-2.5 rounded-lg border border-primary/30 bg-primary/5 p-3 text-xs"
      >
        <Users class="size-4 text-primary shrink-0" />
        <div>
          <span class="font-bold text-foreground">{{
            t("radiology.review_notice_title", "Independent Medical Review:")
          }}</span>
          <span class="text-muted-foreground ml-1">
            {{
              t(
                "radiology.review_notice_desc",
                "Review the findings and clinical impression below. Authorizing this report releases it immediately to the patient chart and ordering clinician.",
              )
            }}
          </span>
        </div>
      </div>

      <!-- PACS Viewport Review Card -->
      <section class="space-y-1.5">
        <div class="flex items-center justify-between">
          <Label
            class="text-xs font-bold text-foreground flex items-center gap-1.5"
          >
            <Scan class="size-3.5 text-sky-500" />
            <span>PACS Image Verification &amp; Diagnostic Series</span>
          </Label>
          <span class="text-[10.5px] font-mono text-muted-foreground">
            Reviewing Modality Series
          </span>
        </div>

        <RadiologyPacsViewer
          :order="props.order"
          :radiology="props.radiology"
          read-only
        />
      </section>

      <!-- Findings Review Card -->
      <section
        class="rounded-lg border border-border bg-surface p-4 space-y-2 shadow-2xs"
      >
        <div
          class="flex items-center justify-between border-b border-border/70 pb-2"
        >
          <span
            class="text-xs font-bold text-foreground uppercase tracking-wide"
          >
            {{
              t("radiology.findings_card_title", {
                study: props.order.studyDescription,
                modality: props.order.modality.toUpperCase(),
              })
            }}
          </span>
          <span class="text-[10px] font-mono text-muted-foreground">
            Acc:
            {{
              props.order.orderNumber ||
              props.order.id.slice(0, 8).toUpperCase()
            }}
          </span>
        </div>

        <div
          class="p-3 bg-muted/20 rounded-md font-mono text-xs whitespace-pre-wrap leading-relaxed border border-border/60 text-foreground"
        >
          {{ props.order.reportSummary }}
        </div>
      </section>

      <!-- Verification Notes & Sign-off Actions (When unverified) -->
      <section
        v-if="!isVerified"
        class="rounded-lg border border-border bg-surface p-4 space-y-3 shadow-2xs"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <Label class="text-xs font-bold text-foreground">
            {{
              t(
                "radiology.verification_note_label",
                "Pathologist / Radiologist Sign-off Remarks",
              )
            }}
          </Label>

          <!-- Quick Presets -->
          <div class="flex flex-wrap items-center gap-1.5">
            <Button
              v-for="p in VERIFICATION_PRESETS"
              :key="p.label"
              type="button"
              variant="outline"
              size="sm"
              class="h-6 text-[10.5px] font-medium px-2 hover:bg-muted cursor-pointer"
              @click="applyPreset(p)"
            >
              <Check class="size-2.5 text-primary mr-1" />
              <span>{{ p.label }}</span>
            </Button>
          </div>
        </div>

        <Textarea
          v-model="note"
          rows="3"
          class="text-xs resize-none bg-background font-mono"
          :placeholder="
            t(
              'radiology.verification_note_placeholder',
              'Add validation comments, clinical correlation remarks, or ACR classification...',
            )
          "
        />

        <div class="flex items-center justify-between pt-1">
          <p class="text-[11px] text-muted-foreground">
            {{
              t(
                "radiology.signoff_notice",
                "Clicking Authorize & Release signs off this study under your medical credentials.",
              )
            }}
          </p>

          <Button
            type="button"
            size="sm"
            class="h-8 gap-1.5 px-4 text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer disabled:opacity-60 shadow-xs"
            :disabled="props.radiology.isVerifying.value"
            @click="verify"
          >
            <ShieldCheck class="size-3.5" />
            <span>{{
              props.radiology.isVerifying.value
                ? t("radiology.verifying", "Authorizing...")
                : t("radiology.verify_release", "Authorize & Release Report")
            }}</span>
          </Button>
        </div>
      </section>
    </template>
  </div>
</template>
