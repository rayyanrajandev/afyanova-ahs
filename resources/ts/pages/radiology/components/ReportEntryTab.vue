/**
 * ReportEntryTab — Modality Examination & Structured Diagnostic Reporting (2027 Standard)
 * =========================================================================================
 * - Equipment / Technique metadata (Transducer, Views, Position, Contrast)
 * - Quick 1-Click Clinical Normal Diagnostic Templates
 * - Structured Diagnostic Findings Editor (Technique, Findings, Impression)
 * - Clear workflow gates (Start Examination → Enter Findings → Submit for Verification)
 */

<script setup lang="ts">
import {
  Activity,
  Check,
  CheckCircle2,
  FileCheck,
  FileEdit,
  FileText,
  Layers,
  Play,
  Send,
  ShieldAlert,
  Sparkles,
  Zap,
} from "lucide-vue-next";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { RadiologyOrder, UseRadiologyOrders } from "../composables/useRadiologyOrders";

const props = defineProps<{
  order: RadiologyOrder;
  radiology: UseRadiologyOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const report = ref("");

interface ReportTemplate {
  label: string;
  modality: string;
  text: string;
}

const TEMPLATES: ReportTemplate[] = [
  {
    label: "Normal Abdominal Ultrasound",
    modality: "ultrasound",
    text: `EXAMINATION: ABDOMINAL ULTRASOUND
TECHNIQUE: Real-time B-mode and color Doppler grayscale examination of the upper abdomen.

FINDINGS:
- LIVER: Normal in size and contour. Homogeneous parenchymal echotexture. No focal solid or cystic mass lesions identified. Intrahepatic biliary tree and portal vein are not dilated.
- GALLBLADDER: Well distended with thin, smooth wall (<3mm). No intraluminal calculi, sludge, or pericholecystic fluid. Common bile duct is normal in caliber (3.8 mm).
- PANCREAS: Visualized portions of the pancreatic head, body, and tail demonstrate normal size and homogeneous echogenicity without focal lesions. Pancreatic duct is not dilated.
- SPLEEN: Normal in size (bipolar diameter 9.8 cm) with homogeneous echotexture.
- KIDNEYS: Both kidneys are normal in size, position, and cortical echogenicity. Bilateral corticomedullary differentiation is preserved. No hydronephrosis, calculus, or mass lesions detected.
- RETROPERITONEUM: Abdominal aorta is normal in caliber. No abdominal lymphadenopathy. No free intraperitoneal fluid or ascites.

IMPRESSION:
Unremarkable abdominal ultrasound study. No acute abdominal sonographic abnormality demonstrated.`,
  },
  {
    label: "Normal Obstetric / Pelvic Ultrasound",
    modality: "ultrasound",
    text: `EXAMINATION: OBSTETRIC ULTRASOUND
TECHNIQUE: Transabdominal obstetric sonography with high-resolution curved array transducer.

FINDINGS:
- Single viable intrauterine gestation in cephalic presentation.
- Regular fetal cardiac activity detected with baseline FHR 144 bpm.
- BIOMETRY:
  * Biparietal Diameter (BPD): Normal for gestational age.
  * Femur Length (FL): Normal for gestational age.
  * Abdominal Circumference (AC): Normal for gestational age.
- PLACENTA: Fundal posterior, Grade I maturity. No retroplacental hematoma or placenta previa.
- AMNIOTIC FLUID: Adequate volume with normal single deepest pocket (SDP 4.5 cm).
- FETAL ANATOMY: Visualized intracranial anatomy, 4-chamber heart, stomach bubble, kidneys, urinary bladder, and spine appear gross morphologically intact.

IMPRESSION:
Single viable intrauterine pregnancy corresponding to gestational dates. Good fetal cardiac and somatic activity with normal liquor volume.`,
  },
  {
    label: "Normal Chest X-Ray (PA View)",
    modality: "xray",
    text: `EXAMINATION: CHEST RADIOGRAPH (PA VIEW)
TECHNIQUE: Standard erect posterior-anterior projection.

FINDINGS:
- LUNGS: Both lung fields are clear with normal vascular markings. No focal consolidation, pneumothorax, pulmonary edema, or active parenchymal infiltrate.
- PLEURA: Bilateral costophrenic angles and cardiophrenic sulci are sharp and clear. No pleural effusion.
- CARDIOMEDIASTINAL SILHOUETTE: Normal cardiac size and contour. Cardiothoracic ratio (CTR) is within normal limits (<0.50). Mediastinum and hilar structures are unremarkable.
- OSSEOUS & SOFT TISSUES: Thoracic cage and soft tissues appear normal without acute rib fractures or lytic/sclerotic bony lesions. Trachea is midline.

IMPRESSION:
Normal chest radiograph. No acute cardiopulmonary disease identified.`,
  },
  {
    label: "Normal Pelvis / Extremity X-Ray",
    modality: "xray",
    text: `EXAMINATION: PLAIN RADIOGRAPH
TECHNIQUE: Orthogonal digital radiographic projections.

FINDINGS:
- Skeletal alignment and bone mineral density are within normal limits.
- Cortical margins are smooth and intact throughout.
- No acute fracture, subluxation, dislocation, or joint space narrowing noted.
- Surrounding periarticular soft tissues demonstrate normal contour without swelling or radio-opaque foreign bodies.

IMPRESSION:
No acute traumatic osseous or joint abnormality demonstrated.`,
  },
];

watch(
  () => props.order.id,
  () => {
    report.value = props.order.reportSummary ?? "";
  },
  { immediate: true },
);

const canStart = computed(() => ["ordered", "scheduled"].includes(props.order.status));
const isWalkIn = computed(() => props.order.status === "ordered");
const canReport = computed(() => props.order.status === "in_progress");
const isReported = computed(() => ["completed", "cancelled"].includes(props.order.status));
const isReleased = computed(() => Boolean(props.order.verifiedAt));

const matchingTemplates = computed(() => {
  const mod = props.order.modality?.toLowerCase() || "";
  return TEMPLATES.filter((t) => t.modality === mod || mod.includes(t.modality));
});

function applyTemplate(tmpl: ReportTemplate) {
  report.value = tmpl.text;
}

async function start() {
  await props.radiology.startStudy(props.order.id);
}

async function submit() {
  if (!report.value.trim()) return;
  await props.radiology.submitReport(props.order.id, report.value.trim());
}
</script>

<template>
  <div class="space-y-4 p-4 max-w-5xl">
    <!-- Header Section -->
    <div class="flex items-center justify-between border-b border-border pb-3">
      <div class="flex items-center gap-2">
        <FileEdit class="size-4 text-primary" />
        <h3 class="text-sm font-bold text-foreground">
          {{ t('radiology.report_title', 'Diagnostic Examination & Structured Report') }}
        </h3>
      </div>

      <div class="flex items-center gap-2">
        <Badge
          variant="outline"
          class="text-[10px] uppercase font-mono px-2 py-0.5"
          :class="[
            canReport ? 'border-purple-500 text-purple-600 bg-purple-500/10' :
            isReported ? 'border-emerald-500 text-emerald-600 bg-emerald-500/10' :
            'border-amber-500 text-amber-600 bg-amber-500/10'
          ]"
        >
          {{
            canReport ? 'Examination in Progress' :
            isReported ? 'Report Submitted' :
            'Awaiting Acquisition'
          }}
        </Badge>
      </div>
    </div>

    <!-- Step 1: Ready to start examination banner -->
    <div
      v-if="canStart"
      class="rounded-lg border border-primary/30 bg-primary/5 p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-2xs"
    >
      <div class="flex items-start gap-3">
        <div class="size-8 rounded-lg bg-primary/15 text-primary flex items-center justify-center shrink-0 mt-0.5">
          <Play class="size-4 fill-current" />
        </div>
        <div>
          <h4 class="text-xs font-bold text-foreground">
            {{ isWalkIn ? t('radiology.walk_in_title', 'Walk-in Examination Ready') : t('radiology.scheduled_ready_title', 'Booked Patient Examination') }}
          </h4>
          <p class="text-xs text-muted-foreground mt-0.5">
            Click Start when the patient is positioned in the examination room. This moves the patient's global visit stage to "In Radiology".
          </p>
        </div>
      </div>

      <Button
        type="button"
        size="sm"
        class="h-8 gap-1.5 px-4 text-xs font-bold shadow-xs cursor-pointer disabled:opacity-60 shrink-0"
        :disabled="props.radiology.isUpdatingOrder.value"
        @click="start"
      >
        <Play class="size-3.5 fill-current" />
        <span>{{ t('radiology.start_study', 'Start Examination') }}</span>
      </Button>
    </div>

    <!-- Step 2: Diagnostic Reporting Console -->
    <div v-if="canReport || isReported" class="space-y-3">
      <!-- Quick Diagnostic Templates Toolbar -->
      <div v-if="canReport" class="flex flex-wrap items-center justify-between gap-2 p-2.5 rounded-lg border border-border/80 bg-muted/20">
        <div class="flex items-center gap-1.5 text-xs text-muted-foreground font-semibold">
          <Sparkles class="size-3.5 text-amber-500" />
          <span>{{ t('radiology.insert_template', 'Quick Clinical Normal Templates:') }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
          <Button
            v-for="tmpl in matchingTemplates"
            :key="tmpl.label"
            type="button"
            variant="outline"
            size="sm"
            class="h-6 text-[11px] font-medium gap-1 px-2 hover:bg-muted cursor-pointer"
            @click="applyTemplate(tmpl)"
          >
            <Check class="size-3 text-emerald-600" />
            <span>{{ tmpl.label }}</span>
          </Button>

          <!-- Fallback All templates dropdown if non-matching -->
          <template v-if="matchingTemplates.length === 0">
            <Button
              v-for="tmpl in TEMPLATES.slice(0, 2)"
              :key="tmpl.label"
              type="button"
              variant="outline"
              size="sm"
              class="h-6 text-[11px] font-medium gap-1 px-2 hover:bg-muted cursor-pointer"
              @click="applyTemplate(tmpl)"
            >
              <Check class="size-3 text-emerald-600" />
              <span>{{ tmpl.label }}</span>
            </Button>
          </template>
        </div>
      </div>

      <!-- Structured Findings Editor -->
      <div class="space-y-1.5">
        <div class="flex items-center justify-between">
          <Label class="text-xs font-bold text-foreground">
            {{ t('radiology.findings_label', 'Structured Diagnostic Findings & Clinical Impression') }}
          </Label>
          <span class="text-[10px] font-mono text-muted-foreground">
            {{ report.length }} characters
          </span>
        </div>

        <Textarea
          v-model="report"
          rows="14"
          class="font-mono text-xs leading-relaxed resize-y bg-surface"
          :disabled="!canReport"
          :placeholder="t('radiology.findings_placeholder', 'Enter technique, observations, anatomical measurements, and clinical impression...')"
        />
      </div>

      <!-- Submit Action & Verification Notice -->
      <div class="flex items-center justify-between border-t border-border pt-3">
        <div>
          <p v-if="canReport" class="text-[11px] text-muted-foreground">
            Submitting will notify the verifying radiologist to authorize and release the report to the doctor chart.
          </p>
          <p v-else-if="!isReleased" class="text-[11px] text-primary font-semibold flex items-center gap-1.5">
            <ShieldAlert class="size-3.5" />
            <span>Report submitted. Awaiting second radiologist verification.</span>
          </p>
        </div>

        <Button
          v-if="canReport"
          type="button"
          size="sm"
          class="h-8 gap-1.5 px-4 text-xs font-bold cursor-pointer disabled:opacity-60"
          :disabled="!report.trim() || props.radiology.isUpdatingOrder.value"
          @click="submit"
        >
          <Send class="size-3.5" />
          <span>{{ t('radiology.submit_report', 'Submit for Verification') }}</span>
        </Button>
      </div>
    </div>
  </div>
</template>
