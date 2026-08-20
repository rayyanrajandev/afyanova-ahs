<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { TriangleAlert } from "lucide-vue-next";
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import type { useAllergyForm } from "@/composables/useAllergyForm";

defineProps<{
  allergyForm: ReturnType<typeof useAllergyForm>;
}>();

const { t } = useI18n();
</script>

<template>
  <Dialog
    :open="allergyForm.showAllergyDialog.value"
    @update:open="(v) => !v && allergyForm.closeAllergyForm()"
  >
    <DialogContent class="sm:max-w-lg border shadow-xl">
      <DialogHeader>
        <div class="flex items-center gap-3">
          <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
            <TriangleAlert class="size-5" />
          </div>
          <div>
            <DialogTitle class="text-base font-bold text-foreground">
              {{ allergyForm.isEditing.value ? t("patient.allergy_edit_title", "Edit Allergy Record") : t("patient.allergy_add_title", "Record Patient Allergy") }}
            </DialogTitle>
            <DialogDescription class="text-xs text-muted-foreground">
              {{ t("patient.allergy_dialog_desc", "Document drug, food, or environmental allergies to trigger clinical safety alerts.") }}
            </DialogDescription>
          </div>
        </div>
      </DialogHeader>

      <form @submit.prevent="allergyForm.submitAllergyForm" class="space-y-4 py-1">
        <!-- Error Alert -->
        <div v-if="allergyForm.allergyFormError.value" class="rounded-md border border-destructive/30 bg-destructive/10 p-3 text-xs text-destructive flex items-center gap-2" role="alert">
          <TriangleAlert class="size-4 shrink-0" />
          <span>{{ allergyForm.allergyFormError.value }}</span>
        </div>

        <div class="space-y-3.5">
          <!-- Row 1: Substance Name & Category -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="space-y-1.5">
              <Label for="allergy-substance" class="text-xs font-semibold text-foreground">
                {{ t("patient.allergy_substance", "Substance / Causative Agent") }} <span class="text-destructive">*</span>
              </Label>
              <Input
                id="allergy-substance"
                v-model="allergyForm.substanceName.value"
                placeholder="e.g. Penicillin, Peanuts, Latex"
                class="h-9 w-full text-xs font-medium"
                required
                autofocus
              />
            </div>

            <div class="space-y-1.5">
              <Label class="text-xs font-semibold text-foreground">
                {{ t("patient.allergy_category", "Category") }}
              </Label>
              <Select v-model="allergyForm.category.value">
                <SelectTrigger class="h-9 w-full text-xs">
                  <SelectValue placeholder="Select Category" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="medication">{{ t("allergy.category_medication", "Medication / Drug") }}</SelectItem>
                  <SelectItem value="food">{{ t("allergy.category_food", "Food") }}</SelectItem>
                  <SelectItem value="environment">{{ t("allergy.category_environment", "Environmental") }}</SelectItem>
                  <SelectItem value="biologic">{{ t("allergy.category_biologic", "Biological") }}</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <!-- Row 2: Manifestation / Reaction & Severity -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="space-y-1.5">
              <Label for="allergy-reaction" class="text-xs font-semibold text-foreground">
                {{ t("patient.allergy_reaction", "Clinical Manifestation / Reaction") }}
              </Label>
              <Input
                id="allergy-reaction"
                v-model="allergyForm.reaction.value"
                placeholder="e.g. Anaphylaxis, Urticaria, Rash"
                class="h-9 w-full text-xs font-medium"
              />
            </div>

            <div class="space-y-1.5">
              <Label class="text-xs font-semibold text-foreground">
                {{ t("patient.allergy_severity", "Criticality / Severity") }}
              </Label>
              <Select v-model="allergyForm.severity.value">
                <SelectTrigger class="h-9 w-full text-xs">
                  <SelectValue placeholder="Select Severity" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="unknown">{{ t("common.unknown", "Unknown") }}</SelectItem>
                  <SelectItem value="mild">{{ t("severity.mild", "Mild") }}</SelectItem>
                  <SelectItem value="moderate">{{ t("severity.moderate", "Moderate") }}</SelectItem>
                  <SelectItem value="severe">{{ t("severity.severe", "Severe (High Risk)") }}</SelectItem>
                  <SelectItem value="life_threatening">{{ t("severity.life_threatening", "Life Threatening (Critical)") }}</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
          
          <!-- Row 3: Clinical Status & Verification Status -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="space-y-1.5">
              <Label class="text-xs font-semibold text-foreground">
                {{ t("patient.allergy_status", "Clinical Status") }}
              </Label>
              <Select v-model="allergyForm.clinicalStatus.value">
                <SelectTrigger class="h-9 w-full text-xs">
                  <SelectValue placeholder="Select Clinical Status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="active">{{ t("status.active", "Active") }}</SelectItem>
                  <SelectItem value="inactive">{{ t("status.inactive", "Inactive") }}</SelectItem>
                  <SelectItem value="resolved">{{ t("status.resolved", "Resolved") }}</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div class="space-y-1.5">
              <Label class="text-xs font-semibold text-foreground">
                {{ t("patient.allergy_verification", "Verification Status") }}
              </Label>
              <Select v-model="allergyForm.verificationStatus.value">
                <SelectTrigger class="h-9 w-full text-xs">
                  <SelectValue placeholder="Select Verification" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="confirmed">{{ t("verification.confirmed", "Confirmed (Verified)") }}</SelectItem>
                  <SelectItem value="unconfirmed">{{ t("verification.unconfirmed", "Unconfirmed (Reported)") }}</SelectItem>
                  <SelectItem value="provisional">{{ t("verification.provisional", "Provisional") }}</SelectItem>
                  <SelectItem value="refuted">{{ t("verification.refuted", "Refuted (Ruled Out)") }}</SelectItem>
                  <SelectItem value="entered_in_error">{{ t("verification.entered_in_error", "Entered in Error") }}</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <!-- Row 4: Clinical Notes -->
          <div class="space-y-1.5">
            <Label for="allergy-notes" class="text-xs font-semibold text-foreground">
              {{ t("patient.allergy_notes", "Clinical Notes / Context") }}
            </Label>
            <Textarea
              id="allergy-notes"
              v-model="allergyForm.notes.value"
              rows="2"
              class="text-xs leading-relaxed w-full min-h-[60px]"
              placeholder="e.g. Patient experienced severe bronchospasm following amoxicillin ingestion in 2023."
            />
          </div>
        </div>

        <DialogFooter class="sm:justify-end gap-2 pt-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            class="h-8 text-xs cursor-pointer"
            @click="allergyForm.closeAllergyForm()"
          >
            {{ t("common.cancel", "Cancel") }}
          </Button>
          <Button
            type="submit"
            size="sm"
            class="h-8 text-xs font-semibold bg-primary text-primary-foreground hover:bg-primary/90 cursor-pointer shadow-xs"
            :disabled="allergyForm.allergyFormSubmitting.value"
          >
            <TriangleAlert class="size-3.5 mr-1" />
            <span>{{ allergyForm.allergyFormSubmitting.value ? t("common.saving", "Saving...") : t("common.save", "Save Allergy") }}</span>
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>

