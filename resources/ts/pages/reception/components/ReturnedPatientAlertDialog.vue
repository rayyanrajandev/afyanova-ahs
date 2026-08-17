<script setup lang="ts">
import { AlertTriangle, UserCheck } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

export interface ReturnedPatientInfo {
  appointmentId: string;
  patientId?: string;
  patientName: string;
  reason: string;
  returnedAt?: string;
}

const props = defineProps<{
  open: boolean;
  patientInfo: ReturnedPatientInfo | null;
}>();

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
  (e: "acknowledge", patientInfo: ReturnedPatientInfo): void;
}>();

const { t } = useI18n();

function handleAcknowledge() {
  if (props.patientInfo) {
    emit("acknowledge", props.patientInfo);
  }
  emit("update:open", false);
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-md border-amber-500/30 bg-background shadow-2xl">
      <DialogHeader>
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
            <AlertTriangle class="h-5 w-5 animate-pulse" aria-hidden="true" />
          </div>
          <div>
            <DialogTitle class="text-lg font-bold text-foreground">
              {{ t("queue.patient_returned_dialog_title", "Patient Returned to Reception") }}
            </DialogTitle>
            <DialogDescription class="text-xs text-muted-foreground">
              {{ t("queue.patient_returned_dialog_desc_hint", "Nursing has returned a patient to Reception for administrative action.") }}
            </DialogDescription>
          </div>
        </div>
      </DialogHeader>

      <div v-if="patientInfo" class="my-3 rounded-lg border border-amber-500/20 bg-amber-500/5 p-4 space-y-3">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            {{ t("patient.label", "Patient") }}
          </span>
          <Badge variant="warning" class="gap-1 text-xs">
            <AlertTriangle class="h-3 w-3" />
            {{ t("queue.tier_returned", "Returned Patients") }}
          </Badge>
        </div>

        <div class="text-base font-bold text-foreground">
          {{ patientInfo.patientName }}
        </div>

        <div class="rounded bg-background/80 p-2.5 text-sm border border-border">
          <div class="text-xs font-medium text-muted-foreground mb-1">
            {{ t("nursing.return_reason_label", "Reason for return:") }}
          </div>
          <div class="font-medium text-amber-700 dark:text-amber-300">
            {{ patientInfo.reason || t("nursing.default_return_reason", "Administrative verification required") }}
          </div>
        </div>
      </div>

      <DialogFooter class="sm:justify-end">
        <Button
          type="button"
          variant="default"
          class="gap-2 bg-amber-600 hover:bg-amber-700 text-white font-medium shadow-md"
          @click="handleAcknowledge"
        >
          <UserCheck class="h-4 w-4" />
          {{ t("queue.acknowledge_view_patient", "Acknowledge & View Patient") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
