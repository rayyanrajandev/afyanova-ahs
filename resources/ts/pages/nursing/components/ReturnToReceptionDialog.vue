/**
 * ReturnToReceptionDialog — Warning modal for transferring patients back to Reception
 * ===================================================================================
 * Warns the nurse before confirming patient return to Reception, requiring a reason
 * (e.g. missing demographics, unverified insurance, wrong clinic queue).
 */

<script setup lang="ts">
import { ArrowLeft, TriangleAlert } from "lucide-vue-next";
import { ref, watch } from "vue";
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
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { ReadinessContext } from "@/stores/queueStore";

const props = defineProps<{
  open: boolean;
  patientName?: string;
  isSubmitting?: boolean;
  readiness?: ReadinessContext | null;
}>();

const emit = defineEmits<{
  "update:open": [value: boolean];
  confirm: [reason: string];
}>();

const { t } = useI18n();

const reasonPreset = ref<string>("demographics");
const customNotes = ref<string>("");

// Smart default (2026-08-14): when dialog opens, pre-select reason matching
// system-detected administrative issues surfaced from Reception/Billing.
watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    if (
      props.readiness?.insuranceVerified === false ||
      (props.readiness?.coverageType === "insurance" && props.readiness?.insuranceVerified === null)
    ) {
      reasonPreset.value = "insurance";
    } else {
      reasonPreset.value = "demographics";
    }

    if (props.readiness?.verificationNotes) {
      customNotes.value = props.readiness.verificationNotes;
    }
  },
  { immediate: true }
);

function onConfirm() {
  const presetText =
    reasonPreset.value === "demographics"
      ? t("nursing.return_reason_demographics")
      : reasonPreset.value === "insurance"
      ? t("nursing.return_reason_insurance")
      : reasonPreset.value === "wrong_clinic"
      ? t("nursing.return_reason_wrong_clinic")
      : t("nursing.return_reason_other");

  const fullReason = customNotes.value.trim()
    ? `${presetText} — ${customNotes.value.trim()}`
    : presetText;

  emit("confirm", fullReason);
}

function onClose() {
  emit("update:open", false);
}
</script>

<template>
  <Dialog :open="open" @update:open="onClose">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <div class="flex items-center gap-2 text-warning font-semibold">
          <TriangleAlert class="h-5 w-5 shrink-0" aria-hidden="true" />
          <DialogTitle>{{ t("nursing.return_to_reception_title") }}</DialogTitle>
        </div>
        <DialogDescription class="pt-2 text-sm text-muted-foreground">
          {{ t("nursing.return_to_reception_warning", { name: patientName ?? t("common.patient") }) }}
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-4 py-2">
        <div class="space-y-2">
          <Label for="return-preset">{{ t("nursing.return_reason_label") }}</Label>
          <select
            id="return-preset"
            v-model="reasonPreset"
            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
          >
            <option value="demographics">{{ t("nursing.return_reason_demographics") }}</option>
            <option value="insurance">{{ t("nursing.return_reason_insurance") }}</option>
            <option value="wrong_clinic">{{ t("nursing.return_reason_wrong_clinic") }}</option>
            <option value="other">{{ t("nursing.return_reason_other") }}</option>
          </select>
          <p v-if="readiness?.insuranceVerified === false" class="text-xs font-medium text-warning">
            {{ t("nursing.return_reason_auto_hint") }}
          </p>
        </div>

        <div class="space-y-2">
          <Label for="return-notes">{{ t("nursing.return_notes_label") }}</Label>
          <Textarea
            id="return-notes"
            v-model="customNotes"
            :placeholder="t('nursing.return_notes_placeholder')"
            rows="3"
          />
        </div>
      </div>

      <DialogFooter class="flex gap-2 sm:justify-end">
        <Button variant="outline" :disabled="isSubmitting" @click="onClose">
          {{ t("common.cancel") }}
        </Button>
        <Button
          variant="destructive"
          class="inline-flex items-center gap-1.5"
          :disabled="isSubmitting"
          @click="onConfirm"
        >
          <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          {{ isSubmitting ? t("common.saving") : t("nursing.confirm_return") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
