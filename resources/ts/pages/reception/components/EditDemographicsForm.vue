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
      <Button variant="ghost" size="sm" @click="edit.closeEditDemographics">
        {{ t("common.close") }}
      </Button>
    </div>

    <!-- @container (workspace consistency audit, 2026-08-11): same fix as
         RegistrationForm.vue, same reason — see that file's comment. -->
    <div class="@container">
      <Form
        :schema="registrationSchema"
        :initial-values="edit.editInitialValues.value"
        class="grid grid-cols-1 gap-3 @lg:grid-cols-3"
        @submit="edit.submitEditDemographics"
      >
        <PatientRegistrationFields :autosave-draft="false" />

        <!-- Button hierarchy matches RegistrationForm.vue's own (2026-08-12,
             direct user feedback) — Save stays the strongest action, Cancel
             drops to ghost since it discards nothing destructive. -->
        <div class="@lg:col-span-3 mt-2 flex gap-3">
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
