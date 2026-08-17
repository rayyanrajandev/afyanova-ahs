/**
 * InpatientAdmissionDialog — Doctor's Inpatient Ward Admission Order (Volume 2.2 §6 / Volume 2.3 §10)
 * ===================================================================================================
 * Modal allowing physicians to order inpatient admissions with Ward/Bed allocation and directives.
 */

<script setup lang="ts">
import { BedDouble, CheckCircle2, ShieldAlert } from "lucide-vue-next";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { useToast } from "@/composables/useToast";
import { useNursingAdmissionStore } from "@/stores/nursingAdmissionStore";
import type { Patient } from "@/stores/patientStore";

const props = defineProps<{
  open: boolean;
  patient: Patient | null;
  encounterId: string | null;
}>();

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
  (e: "admitted"): void;
}>();

const { t } = useI18n({ useScope: "global" });
const toast = useToast();
const admissionStore = useNursingAdmissionStore();

const ward = ref("General Male Ward");
const bed = ref("Bed 04");
const admissionReason = ref("");
const directives = ref("");

const WARDS = [
  "General Male Ward",
  "General Female Ward",
  "Pediatric Ward",
  "Maternity & Labor Ward",
  "Surgical Inpatient Ward",
  "Intensive Care Unit (ICU)",
];

const BEDS = ["Bed 01", "Bed 02", "Bed 03", "Bed 04", "Bed 05", "Bed 06", "Bed 07", "Bed 08", "Bed 09", "Bed 10"];

async function handleConfirmAdmission() {
  if (!props.patient || !props.encounterId) return;

  const result = await admissionStore.createAdmission({
    patientId: props.patient.id,
    encounterId: props.encounterId,
    admittedAt: new Date().toISOString(),
    ward: ward.value,
    bed: bed.value,
    admissionReason: admissionReason.value || "Physician Inpatient Care Directives",
    notes: directives.value,
  });

  if (result) {
    toast.success(t("clinician.confirm_admission", "Patient admitted to ward successfully!"));
    emit("update:open", false);
    emit("admitted");
  } else {
    toast.error(admissionStore.error || "Failed to confirm admission");
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-lg border shadow-2xl">
      <DialogHeader>
        <div class="flex items-center gap-3">
          <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-indigo-500/10 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-400">
            <BedDouble class="size-5" />
          </div>
          <div>
            <DialogTitle class="text-base font-bold text-foreground">
              {{ t("clinician.admit_dialog_title") }}
            </DialogTitle>
            <DialogDescription class="text-xs text-muted-foreground">
              {{ t("clinician.admit_dialog_desc") }}
            </DialogDescription>
          </div>
        </div>
      </DialogHeader>

      <div class="space-y-4 py-2">
        <!-- Ward & Bed Grid -->
        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <Label for="admission-ward" required class="text-xs font-semibold text-foreground">
              {{ t("clinician.ward") }}
            </Label>
            <select
              id="admission-ward"
              v-model="ward"
              class="h-8 w-full rounded-md border border-border bg-background px-2.5 text-xs font-medium text-foreground"
            >
              <option v-for="w in WARDS" :key="w" :value="w">{{ w }}</option>
            </select>
          </div>

          <div class="space-y-1.5">
            <Label for="admission-bed" required class="text-xs font-semibold text-foreground">
              {{ t("clinician.bed") }}
            </Label>
            <select
              id="admission-bed"
              v-model="bed"
              class="h-8 w-full rounded-md border border-border bg-background px-2.5 text-xs font-medium text-foreground"
            >
              <option v-for="b in BEDS" :key="b" :value="b">{{ b }}</option>
            </select>
          </div>
        </div>

        <!-- Admission Reason / Diagnosis -->
        <div class="space-y-1.5">
          <Label for="admission-reason" class="text-xs font-semibold text-foreground">
            Admission Diagnosis / Clinical Reason
          </Label>
          <Input
            id="admission-reason"
            v-model="admissionReason"
            type="text"
            class="h-8 text-xs font-medium"
            placeholder="e.g. Severe Malaria with dehydration, Acute Pneumonia"
          />
        </div>

        <!-- Directives for Inpatient Nursing -->
        <div class="space-y-1.5">
          <Label for="admission-notes" class="text-xs font-semibold text-foreground">
            {{ t("clinician.admission_notes") }}
          </Label>
          <Textarea
            id="admission-notes"
            v-model="directives"
            rows="3"
            class="text-xs leading-relaxed"
            :placeholder="t('clinician.admission_notes_placeholder')"
          />
        </div>
      </div>

      <DialogFooter class="sm:justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          class="h-8 text-xs cursor-pointer"
          @click="emit('update:open', false)"
        >
          {{ t("common.cancel") }}
        </Button>
        <Button
          type="button"
          size="sm"
          class="h-8 gap-1.5 text-xs font-semibold bg-indigo-600 hover:bg-indigo-700 text-white cursor-pointer shadow-xs"
          :disabled="admissionStore.isSaving"
          @click="handleConfirmAdmission"
        >
          <BedDouble class="size-3.5" />
          <span>{{ admissionStore.isSaving ? t("common.loading") : t("clinician.confirm_admission") }}</span>
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
