/**
 * RegistrationForm — main-pane new-patient registration (Volume 2.1 §6)
 * ==========================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit).
 * Pure template extraction — no behavior change.
 *
 * Only calls into `registration`'s functions and reads its refs; never
 * assigns through the prop path, so (unlike ScheduleView/ArrivalIntakeDialog)
 * it does NOT need `vue/no-mutating-props` disabled.
 */

<script setup lang="ts">
import { Check } from "lucide-vue-next";
import type { GenericObject } from "vee-validate";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import Form from "@/components/common/Form.vue";
import PatientRegistrationFields from "@/components/common/PatientRegistrationFields.vue";
import { Button } from "@/components/ui/button";
import type { usePatientRegistration } from "../composables/usePatientRegistration";
import { registrationSchema } from "../registrationSchema";

const props = defineProps<{
  registration: ReturnType<typeof usePatientRegistration>;
}>();

const { t } = useI18n();

/**
 * Which Save button was actually clicked (Volume 2.1 §6, 2026-08-12 —
 * "Save & Add Another"). Both buttons are `type="submit"` so vee-validate
 * runs the exact same validation for either — the only difference is what
 * happens *after* a successful save. A button's own `click` handler fires
 * before the <form>'s `submit` event, so setting this here and reading it
 * in handleSubmit() reliably captures intent without needing two separate
 * <Form> instances or a native `submitter`-inspecting workaround. Defaults
 * to "finish" so anything that submits the form without going through a
 * button click (e.g. pressing Enter in a field) keeps the normal Save
 * Patient behavior.
 */
const saveIntent = ref<"finish" | "addAnother">("finish");

function handleSubmit(values: GenericObject) {
  props.registration.submitRegistration(values, {
    andAddAnother: saveIntent.value === "addAnother",
  });
}
</script>

<template>
  <div>
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-foreground">
        {{ t("patient.register") }}
      </h2>
      <div class="flex items-center gap-3">
        <span
          v-if="registration.draftSavedAt.value"
          class="flex items-center gap-1 text-xs text-muted-foreground"
          role="status"
        >
          <Check class="h-3 w-3" aria-hidden="true" />
          {{ t("registration.draft_saved") }}
        </span>
        <Button variant="ghost" size="sm" @click="registration.closeRegistration">
          {{ t("common.close") }}
        </Button>
      </div>
    </div>

    <!-- @container (workspace consistency audit, 2026-08-11): this form
         sits in the same resizable main pane as PatientProfileView's
         cards, which already needed this same fix for the same reason
         (its own docblock has the full story) — a fixed `grid-cols-2`
         doesn't know the *pane*, not the browser window, is what's
         actually narrow here. -->
    <div class="@container">
      <Form
        :key="registration.formKey.value"
        :schema="registrationSchema"
        :initial-values="registration.registrationInitialValues.value"
        class="grid grid-cols-1 gap-3 @lg:grid-cols-3"
        @submit="handleSubmit"
      >
        <PatientRegistrationFields @draft-saved="registration.handleDraftSaved" />

        <!--
          Button hierarchy (2026-08-12, direct user feedback): three
          distinct weights, not two-secondary-plus-one-primary — Save
          Patient stays the strongest (default/filled) since it's the
          action that actually finishes the workflow; Save & Add Another
          is a real, deliberate action so it keeps a filled `secondary`
          treatment (visible, but clearly a notch below primary); Cancel
          destroys nothing and is the one a receptionist should almost
          never need mid-task, so it drops to `ghost` — text-only, no
          fill — matching the same de-emphasis this form's own "Close"
          button above already uses for the identical reason.
        -->
        <div class="@lg:col-span-3 mt-2 flex flex-wrap items-center gap-3">
          <Button
            id="reception-registration-save"
            type="submit"
            @click="saveIntent = 'finish'"
          >
            {{ t("patient.save_patient") }}
          </Button>
          <Button
            type="submit"
            variant="secondary"
            @click="saveIntent = 'addAnother'"
          >
            {{ t("patient.save_and_add_another") }}
          </Button>
          <Button
            type="button"
            variant="ghost"
            @click="registration.closeRegistration"
          >
            {{ t("common.cancel") }}
          </Button>
        </div>
      </Form>
    </div>
  </div>
</template>
