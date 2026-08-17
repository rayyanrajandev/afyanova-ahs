/**
 * ConsultationTakeoverDialog — taking over a colleague's open consultation
 * ========================================================================
 * The 409 conflict was already handled and handleConfirmTakeover() already
 * existed, but nothing ever rendered a confirmation — so a doctor who hit
 * "another clinician is already with this patient" had no way to proceed from
 * the UI at all, and the backend's takeover path was unreachable
 * (reports/laboratory-workspace-flow-plan.md, phase 5).
 *
 * Taking a patient off a colleague is a deliberate act with a named reason: the
 * backend records the takeover and who authorised it, so the reason is required
 * here rather than optional. That is the whole point of arbitrating ownership
 * instead of letting the second click silently win.
 */

<script setup lang="ts">
import { ShieldAlert, UserCog } from "lucide-vue-next";
import { computed, ref, watch } from "vue";
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

const props = defineProps<{
  open: boolean;
  /** Display name of the clinician who currently holds the consultation, when known. */
  ownerName?: string | null;
  patientName?: string | null;
  isSubmitting?: boolean;
}>();

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
  (e: "confirm", reason: string): void;
}>();

const { t } = useI18n({ useScope: "global" });

const reason = ref("");

// A stale reason must never be carried into the next takeover — it would attach
// one consultation's justification to a different patient's audit row.
watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) reason.value = "";
  },
);

const canConfirm = computed(() => reason.value.trim().length > 0 && !props.isSubmitting);

function handleConfirm() {
  if (!canConfirm.value) return;

  emit("confirm", reason.value.trim());
}
</script>

<template>
  <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2 text-base">
          <ShieldAlert class="size-4 text-warning" />
          {{ t('clinician.takeover_title', 'Take Over This Consultation?') }}
        </DialogTitle>
        <DialogDescription class="text-xs leading-relaxed">
          {{
            ownerName
              ? t('clinician.takeover_warning_named', { owner: ownerName, name: patientName ?? t('common.this_patient', 'this patient') })
              : t('clinician.takeover_warning', 'Another clinician currently has this consultation open. Taking over will release it from them and record you as the attending clinician.')
          }}
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-1.5">
        <Label for="takeover-reason" class="text-xs font-semibold">
          {{ t('clinician.takeover_reason_label', 'Reason for taking over') }}
        </Label>
        <Textarea
          id="takeover-reason"
          v-model="reason"
          rows="3"
          class="text-xs resize-none"
          :placeholder="t('clinician.takeover_reason_placeholder', 'e.g. Colleague called to emergency; patient handed over to me')"
        />
        <p class="text-xs text-muted-foreground">
          {{ t('clinician.takeover_reason_hint', 'Recorded on the visit audit trail alongside your name.') }}
        </p>
      </div>

      <DialogFooter class="gap-2">
        <Button variant="outline" size="sm" class="cursor-pointer" @click="emit('update:open', false)">
          {{ t('common.cancel', 'Cancel') }}
        </Button>
        <Button
          size="sm"
          class="gap-1.5 cursor-pointer"
          :disabled="!canConfirm"
          @click="handleConfirm"
        >
          <UserCog class="size-3.5" />
          {{ t('clinician.takeover_confirm', 'Take Over Consultation') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
