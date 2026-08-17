/**
 * RegistrationForm — main-pane new-patient registration (Volume 2.1 §6)
 * ==========================================================================
 * 2027 Modern Enterprise Health System Upgrades:
 * - High-velocity 1-click "Save & Check In Now" (Walk-in OPD)
 * - Direct 1-click "Save & Emergency Check-In" (Routes to ER/Triage as CRITICAL)
 * - Card-elevated section styling
 * - Seamless keyboard submission (Ctrl+Enter)
 */

<script setup lang="ts">
import { Check, CheckCircle2, ChevronDown, Siren, UserPlus, X, Zap } from "lucide-vue-next";
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuPortal,
  DropdownMenuRoot,
  DropdownMenuTrigger,
} from "reka-ui";
import type { GenericObject } from "vee-validate";
import { nextTick, onBeforeUnmount, onMounted, ref } from "vue";
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
 * Which Save action was triggered:
 * - 'saveAndCheckIn': Saves patient, records insurance (if any), creates walk-in arrival, and routes to OPD Triage queue.
 * - 'saveAndEmergency': Saves patient, creates EMERGENCY arrival with CRITICAL priority, and routes to ER/Triage immediately.
 * - 'finish': Saves demographics & insurance, opens the patient profile.
 * - 'addAnother': Saves patient and remounts clean form for the next patient.
 */
const saveIntent = ref<"saveAndCheckIn" | "saveAndEmergency" | "finish" | "addAnother">("saveAndCheckIn");

function handleSubmit(values: GenericObject) {
  const isEmergency = saveIntent.value === "saveAndEmergency";
  const isCheckIn = saveIntent.value === "saveAndCheckIn" || isEmergency;

  props.registration.submitRegistration(values, {
    andCheckIn: isCheckIn,
    arrivalMode: isEmergency ? "emergency" : "walk_in",
    andAddAnother: saveIntent.value === "addAnother",
  });
}

function triggerSave(intent: "saveAndCheckIn" | "saveAndEmergency" | "finish" | "addAnother") {
  saveIntent.value = intent;
  nextTick(() => {
    if (intent === "saveAndEmergency") {
      document.getElementById("reception-registration-save-emergency")?.click();
    } else if (intent === "finish") {
      document.getElementById("reception-registration-save")?.click();
    } else if (intent === "addAnother") {
      document.getElementById("reception-registration-save-add-another")?.click();
    } else {
      document.getElementById("reception-registration-save-checkin")?.click();
    }
  });
}

function handleKeydown(e: KeyboardEvent) {
  if ((e.ctrlKey || e.metaKey) && e.key === "Enter") {
    e.preventDefault();
    const submitBtn = document.getElementById("reception-registration-save-checkin") as HTMLButtonElement | null;
    submitBtn?.click();
  }
}

onMounted(() => {
  window.addEventListener("keydown", handleKeydown);
});

onBeforeUnmount(() => {
  window.removeEventListener("keydown", handleKeydown);
});
</script>

<template>
  <div class="flex h-full flex-col overflow-hidden rounded-lg">
    <!-- 1. Sticky Header Bar -->
    <header class="flex shrink-0 items-center justify-between border-b border-border bg-surface px-4 py-2.5 sm:px-5 sm:py-3 rounded-t-lg">
      <div class="flex items-center gap-2.5">
        <div class="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
          <UserPlus class="size-4.5" aria-hidden="true" />
        </div>
        <div>
          <h2 class="text-sm sm:text-base font-bold tracking-tight text-foreground">
            {{ t("patient.register") }}
          </h2>
          <p class="text-[11px] text-muted-foreground">
            {{ t("registration.subtitle") }}
          </p>
        </div>
      </div>

      <div class="flex items-center gap-2 sm:gap-3">
        <span
          v-if="registration.draftSavedAt.value"
          class="flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400"
          role="status"
        >
          <Check class="size-3" aria-hidden="true" />
          {{ t("registration.draft_saved") }}
        </span>

        <Button
          variant="ghost"
          size="icon"
          class="size-7.5 text-muted-foreground hover:text-foreground cursor-pointer"
          :aria-label="t('common.close')"
          @click="registration.closeRegistration"
        >
          <X class="size-4" aria-hidden="true" />
        </Button>
      </div>
    </header>

    <!-- 2. Scrollable Form Canvas -->
    <div class="flex-1 overflow-y-auto p-3 sm:p-4 @container">
      <Form
        id="reception-patient-registration-form"
        :key="registration.formKey.value"
        :schema="registrationSchema"
        :initial-values="registration.registrationInitialValues.value"
        class="w-full space-y-3"
        @submit="handleSubmit"
      >
        <PatientRegistrationFields
          :registration="registration"
          @draft-saved="registration.handleDraftSaved"
        />

        <!-- Hidden submit targets to support programmatic clicks & keyboard shortcuts -->
        <button
          id="reception-registration-save-checkin"
          type="submit"
          class="sr-only"
          tabindex="-1"
          :disabled="registration.isSubmitting.value"
          @click="saveIntent = 'saveAndCheckIn'"
        />
        <button
          id="reception-registration-save-emergency"
          type="submit"
          class="sr-only"
          tabindex="-1"
          :disabled="registration.isSubmitting.value"
          @click="saveIntent = 'saveAndEmergency'"
        />
        <button
          id="reception-registration-save"
          type="submit"
          class="sr-only"
          tabindex="-1"
          :disabled="registration.isSubmitting.value"
          @click="saveIntent = 'finish'"
        />
        <button
          id="reception-registration-save-add-another"
          type="submit"
          class="sr-only"
          tabindex="-1"
          :disabled="registration.isSubmitting.value"
          @click="saveIntent = 'addAnother'"
        />
      </Form>
    </div>

    <!-- 3. Pinned Footer Action Bar (Focused on Primary Actions, No Redundant Cancel) -->
    <footer class="flex shrink-0 items-center justify-between border-t border-border bg-surface px-4 py-2.5 sm:px-5 sm:py-2.5 shadow-xs">
      <!-- Split Button: Primary Save & Check-In + Dropdown Menu for Secondary Options -->
      <div class="inline-flex items-stretch rounded-lg shadow-xs">
        <Button
          type="button"
          size="default"
          class="rounded-r-none gap-2 font-semibold cursor-pointer border-r border-primary-foreground/20"
          :disabled="registration.isSubmitting.value"
          @click="triggerSave('saveAndCheckIn')"
        >
          <Zap class="size-4 fill-current" aria-hidden="true" />
          <span>⚡ {{ t("registration.save_and_checkin") }}</span>
        </Button>

        <DropdownMenuRoot>
          <DropdownMenuTrigger as-child>
            <Button
              type="button"
              size="default"
              class="rounded-l-none px-2.5 cursor-pointer"
              :disabled="registration.isSubmitting.value"
              aria-label="More registration save options"
            >
              <ChevronDown class="size-4" aria-hidden="true" />
            </Button>
          </DropdownMenuTrigger>

          <DropdownMenuPortal>
            <DropdownMenuContent
              align="start"
              side="top"
              :side-offset="8"
              class="z-50 min-w-72 rounded-xl border border-border bg-popover p-1.5 text-popover-foreground shadow-elevation-md outline-none animate-in fade-in-80 zoom-in-95"
            >
              <!-- 1. Save & Emergency Check-In (Critical Route) -->
              <DropdownMenuItem
                class="flex cursor-pointer select-none items-center gap-3 rounded-lg px-3 py-2.5 text-xs font-medium text-destructive outline-none transition-colors hover:bg-destructive/10 focus:bg-destructive/10"
                @select="triggerSave('saveAndEmergency')"
              >
                <Siren class="size-4.5 shrink-0 text-destructive animate-pulse" aria-hidden="true" />
                <div class="flex flex-col">
                  <span class="font-semibold text-sm">{{ t("registration.save_and_emergency") }}</span>
                  <span class="text-[11px] text-muted-foreground">Immediate triage / ER priority routing</span>
                </div>
              </DropdownMenuItem>

              <div class="my-1 h-px bg-border/60" />

              <!-- 2. Save Patient (Profile Only) -->
              <DropdownMenuItem
                class="flex cursor-pointer select-none items-center gap-3 rounded-lg px-3 py-2.5 text-xs font-medium outline-none transition-colors hover:bg-muted focus:bg-muted"
                @select="triggerSave('finish')"
              >
                <CheckCircle2 class="size-4.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                <div class="flex flex-col">
                  <span class="font-semibold text-sm">{{ t("patient.save_patient") }}</span>
                  <span class="text-[11px] text-muted-foreground">Save record without placing in queue</span>
                </div>
              </DropdownMenuItem>

              <!-- 3. Save & Add Another -->
              <DropdownMenuItem
                class="flex cursor-pointer select-none items-center gap-3 rounded-lg px-3 py-2.5 text-xs font-medium outline-none transition-colors hover:bg-muted focus:bg-muted"
                @select="triggerSave('addAnother')"
              >
                <UserPlus class="size-4.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                <div class="flex flex-col">
                  <span class="font-semibold text-sm">{{ t("patient.save_and_add_another") }}</span>
                  <span class="text-[11px] text-muted-foreground">Save and immediately register next patient</span>
                </div>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenuPortal>
        </DropdownMenuRoot>
      </div>

      <!-- Keyboard Shortcut Helper -->
      <span class="text-[11px] text-muted-foreground font-mono">
        <kbd class="rounded border border-border bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-foreground">Ctrl+Enter</kbd> to save
      </span>
    </footer>
  </div>
</template>
