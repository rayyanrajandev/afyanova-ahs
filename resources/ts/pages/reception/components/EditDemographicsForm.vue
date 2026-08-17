/**
 * EditDemographicsForm — main-pane demographics edit (Volume 2.1 §8.3)
 * ==========================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit).
 * Pure template extraction — no behavior change.
 *
 * Only calls into `edit`'s functions and reads its refs; never assigns
 * through the prop path, so it does NOT need `vue/no-mutating-props`
 * disabled (same reasoning as RegistrationForm.vue).
 */

<script setup lang="ts">
import { X } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import Form from "@/components/common/Form.vue";
import PatientRegistrationFields from "@/components/common/PatientRegistrationFields.vue";
import { Button } from "@/components/ui/button";
import type { useEditDemographics } from "../composables/useEditDemographics";
import { registrationSchema } from "../registrationSchema";

defineProps<{
  edit: ReturnType<typeof useEditDemographics>;
}>();

const { t } = useI18n();
</script>

<template>
  <div>
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-foreground">
        {{ t("common.edit") }} — {{ t("patient.demographics") }}
      </h2>
      <!-- Icon-only (2026-08-13, cross-workspace consistency pass) — matches
           RegistrationForm.vue's and PatientProfileView.vue's header close,
           see their docblocks. The bottom "Cancel" button is a separate,
           end-of-form affordance and is untouched. -->
      <Button
        variant="ghost"
        size="icon"
        :aria-label="t('common.close')"
        @click="edit.closeEditDemographics"
      >
        <X class="h-3.5 w-3.5" aria-hidden="true" />
      </Button>
    </div>

    <!-- @container (workspace consistency audit, 2026-08-11): same fix as
         RegistrationForm.vue, same reason — see that file's comment. -->
    <div class="@container">
      <Form
        :schema="registrationSchema"
        :initial-values="edit.editInitialValues.value"
        class="space-y-4"
        @submit="edit.submitEditDemographics"
      >
        <PatientRegistrationFields :autosave-draft="false" />

        <div class="mt-4 flex items-center gap-3 border-t border-border pt-3.5">
          <Button type="submit">
            {{ t("common.save") }}
          </Button>
          <Button
            type="button"
            variant="ghost"
            @click="edit.closeEditDemographics"
          >
            {{ t("common.cancel") }}
          </Button>
        </div>
      </Form>
    </div>
  </div>
</template>
