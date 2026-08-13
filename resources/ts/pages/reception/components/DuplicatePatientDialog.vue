/**
 * DuplicatePatientDialog — duplicate-match confirmation (Volume 2.1 §6.2 /
 * §7.3, Volume 1.2 §10, Volume 3.7 T2.4)
 * ==========================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit).
 * Pure template extraction, no behavior change.
 *
 * Only calls into `registration`'s functions and reads its refs (the Dialog
 * uses the `:open`/`@update:open` split, not `v-model:open`, so nothing
 * writes through the prop path) — no `vue/no-mutating-props` disable needed,
 * same reasoning as QueuePanel.vue.
 */

<script setup lang="ts">
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
import type { usePatientRegistration } from "../composables/usePatientRegistration";

defineProps<{
  registration: ReturnType<typeof usePatientRegistration>;
}>();

const { t } = useI18n();
</script>

<template>
  <Dialog
    :open="registration.showDuplicateDialog.value"
    @update:open="(v) => !v && registration.cancelDuplicate()"
  >
    <DialogContent>
      <DialogHeader>
        <DialogTitle>{{ t("registration.duplicate_title") }}</DialogTitle>
        <DialogDescription>
          {{ t("registration.duplicate_description") }}
        </DialogDescription>
      </DialogHeader>

      <ul class="space-y-2">
        <li
          v-for="(match, index) in registration.duplicateMatches.value"
          :key="match.id ?? `dup-${index}`"
          class="flex items-center justify-between gap-3 rounded-md border border-border p-3 text-sm"
        >
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <span class="font-medium text-foreground"
                >{{ match.firstName }} {{ match.lastName }}</span
              >
              <span
                v-if="match.patientNumber"
                class="clinical-value text-muted-foreground"
              >
                {{ t("patient.mrn") }} {{ match.patientNumber }}
              </span>
            </div>
            <div class="mt-1 text-xs text-muted-foreground">
              {{ t("patient.date_of_birth") }}: {{ match.dateOfBirth }} ·
              {{ t("patient.phone") }}: {{ match.phone }}
            </div>
          </div>
          <!-- Replaces the old "Proceed anyway" (removed 2026-08-11 — see
               usePatientRegistration.ts's file header for why it could
               never actually succeed for a hard duplicate). Opening the
               existing record is the correct action here, not retrying. -->
          <Button
            size="sm"
            variant="secondary"
            class="shrink-0"
            @click="registration.openExistingDuplicate(match.id)"
          >
            {{ t("registration.duplicate_open_existing") }}
          </Button>
        </li>
      </ul>

      <DialogFooter>
        <Button variant="secondary" @click="registration.cancelDuplicate">
          {{ t("common.cancel") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
