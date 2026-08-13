<!--
  InsuranceFormDialog — Add/edit patient insurance (Volume 2.1 §8.1,
  Volume 3.7 §16 #10)
  ==========================================================================
  Same shared-composable-instance shape as CancelQueueItemDialog.vue —
  `vue/no-mutating-props` disabled for the same reason (see that file's
  docblock).
-->

<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- see file header docblock */
import { useI18n } from "vue-i18n";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { useInsuranceForm } from "../composables/useInsuranceForm";

defineProps<{
  insuranceForm: ReturnType<typeof useInsuranceForm>;
}>();

const { t } = useI18n();
</script>

<template>
  <Dialog
    :open="insuranceForm.showInsuranceDialog.value"
    @update:open="(v) => !v && insuranceForm.closeInsuranceForm()"
  >
    <DialogContent>
      <DialogHeader>
        <DialogTitle>
          {{ insuranceForm.isEditing.value ? t("insurance.edit_title") : t("insurance.add_title") }}
        </DialogTitle>
      </DialogHeader>

      <div class="space-y-3">
        <div class="space-y-1.5">
          <Label for="insurance-provider">{{ t("patient.insurance_provider") }}</Label>
          <Input
            id="insurance-provider"
            v-model="insuranceForm.insuranceProvider.value"
            :placeholder="t('insurance.provider_placeholder')"
          />
        </div>
        <div class="space-y-1.5">
          <Label for="insurance-member-id">{{ t("patient.insurance_member_id") }}</Label>
          <Input
            id="insurance-member-id"
            v-model="insuranceForm.memberId.value"
            class="clinical-value"
            :aria-invalid="!!insuranceForm.insuranceFormError.value"
          />
        </div>
        <div class="space-y-1.5">
          <Label for="insurance-policy-number">{{ t("insurance.policy_number") }}</Label>
          <Input id="insurance-policy-number" v-model="insuranceForm.policyNumber.value" class="clinical-value" />
        </div>
        <div class="space-y-1.5">
          <Label for="insurance-plan-name">{{ t("insurance.plan_name") }}</Label>
          <Input id="insurance-plan-name" v-model="insuranceForm.planName.value" />
        </div>
        <p v-if="insuranceForm.insuranceFormError.value" class="text-xs text-critical" role="alert">
          {{ insuranceForm.insuranceFormError.value }}
        </p>
      </div>

      <DialogFooter>
        <Button variant="secondary" @click="insuranceForm.closeInsuranceForm">
          {{ t("common.cancel") }}
        </Button>
        <Button
          :disabled="insuranceForm.insuranceFormSubmitting.value"
          @click="insuranceForm.submitInsuranceForm"
        >
          {{ t("common.save") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
