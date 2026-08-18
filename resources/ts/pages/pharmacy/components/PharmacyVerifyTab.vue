/** * PharmacyVerifyTab — Pharmacist Sign-Off, Discharge Release & Audit (Volume
2.6) *
=================================================================================
* Styled identically to Laboratory and Radiology Verification station: * -
Two-eye senior pharmacist verification banner & checklist * - Dispensing summary
card * - Supervisor remarks & electronic chart authorization */

<script setup lang="ts">
import { Award, CheckCircle2, ShieldCheck } from "lucide-vue-next";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  pharmacyStageOf,
  type PharmacyOrder,
  type UsePharmacyOrders,
} from "../composables/usePharmacyOrders";

const props = defineProps<{
  order: PharmacyOrder;
  patientOrders: PharmacyOrder[];
  pharmacy: UsePharmacyOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const verificationRemarks = ref<string>(
  "Dispensation verified and counseled according to clinical protocol.",
);

const isAlreadyVerified = computed(() => {
  return !!props.order.verifiedAt;
});

const isReadyToVerify = computed(() => {
  return (
    props.order.status === "dispensed" ||
    props.order.status === "partially_dispensed"
  );
});

/**
 * Sign-off is only open on a dispense that has happened and not yet been
 * signed — the same precondition VerifyPharmacyOrderDispenseUseCase enforces.
 */
const canVerify = computed(
  () => pharmacyStageOf(props.order) === "dispensed_unverified",
);

async function handleVerify(): Promise<void> {
  await props.pharmacy.verifyDispense(verificationRemarks.value);
}
</script>

<template>
  <div class="w-full min-w-0 p-4 space-y-4">
    <!-- Dispensation Summary Card -->
    <div
      class="rounded-lg border border-border bg-surface p-4 space-y-3 shadow-2xs"
    >
      <h3
        class="text-xs font-bold uppercase tracking-wider text-muted-foreground"
      >
        Dispensing Summary
      </h3>

      <div
        class="grid grid-cols-2 sm:grid-cols-4 gap-3 p-3 bg-muted/40 rounded-lg border border-border/70 text-xs"
      >
        <div>
          <span class="text-muted-foreground block">Medication:</span>
          <strong class="text-foreground">{{ order.medicationName }}</strong>
        </div>
        <div>
          <span class="text-muted-foreground block">Quantity Dispensed:</span>
          <strong class="text-foreground"
            >{{ order.quantityDispensed || order.quantityPrescribed }}
            {{ order.dispensedUnit || order.prescribedUnit || "units" }}</strong
          >
        </div>
        <div>
          <span class="text-muted-foreground block">Prescribing Doctor:</span>
          <strong class="text-foreground"
            >Dr. {{ order.orderingClinician || "Clinician" }}</strong
          >
        </div>
        <div>
          <span class="text-muted-foreground block">Charge:</span>
          <strong class="text-foreground font-mono">{{
            order.totalPrice
              ? order.totalPrice.toLocaleString() + " TZS"
              : "Covered"
          }}</strong>
        </div>
      </div>

      <div
        v-if="order.dispensingNotes"
        class="p-3 rounded-md bg-surface border text-xs"
      >
        <span class="text-muted-foreground font-bold block mb-0.5"
          >Dispensing / Counseling Remarks:</span
        >
        <p class="text-foreground italic">{{ order.dispensingNotes }}</p>
      </div>
    </div>

    <!-- Pharmacist Sign-Off Station (Active when ready to verify) -->
    <div
      v-if="!isAlreadyVerified && isReadyToVerify"
      class="rounded-lg border border-border bg-surface p-4 space-y-3 shadow-2xs"
    >
      <div>
        <h3 class="text-sm font-bold text-foreground flex items-center gap-2">
          <Award class="size-4 text-primary" />
          <span>Electronic Supervisor Sign-Off</span>
        </h3>
        <p class="text-xs text-muted-foreground mt-0.5">
          Signing confirms that the physical drug, dose, batch, and labeling
          match the prescription order.
        </p>
      </div>

      <div class="space-y-1.5 pt-1">
        <Label class="text-xs font-bold text-foreground"
          >Verification Remarks / Audit Note</Label
        >
        <Textarea
          v-model="verificationRemarks"
          rows="2"
          class="text-xs font-medium"
          placeholder="Enter verification comments..."
        />
      </div>

      <div class="flex items-center justify-end pt-2">
        <Button
          class="gap-1.5 bg-primary text-primary-foreground hover:bg-primary/90 font-semibold cursor-pointer shadow-xs"
          :disabled="!canVerify || pharmacy.isActionLoading.value"
          :title="
            canVerify
              ? ''
              : t(
                  'pharmacy.locked_verify',
                  'Locked until the medicine has been dispensed',
                )
          "
          @click="handleVerify"
        >
          <ShieldCheck class="size-4" />
          <span>Verify & Release to Patient Chart</span>
        </Button>
      </div>
    </div>

    <!-- Verified Completed Status Card -->
    <div
      v-else-if="isAlreadyVerified"
      class="p-6 rounded-lg bg-emerald-500/5 border border-emerald-500/20 text-xs text-center space-y-1.5"
    >
      <CheckCircle2
        class="size-8 text-emerald-600 dark:text-emerald-400 mx-auto"
      />
      <div class="font-bold text-foreground text-sm">
        Order Fully Dispensed & Verified
      </div>
      <div class="text-muted-foreground max-w-md mx-auto">
        This record is finalized and synced with the Electronic Health Record
        and Billing Station.
      </div>
    </div>
  </div>
</template>
