/**
 * PatientListPanel — Nursing context-pane "Patients" tab (Volume 2.3 §4.1)
 * =========================================================================
 * Extracted from nursing/Index.vue (2026-08-13, component decomposition —
 * Reception-style separation of concerns). Renders the search box + patient
 * DataTable for the Patients tab. Pure presentation: all state/logic lives in
 * `useNursingPatientList`, passed in as a prop.
 */

<script setup lang="ts">
import { History, Pin } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import DataTable from "@/components/common/DataTable.vue";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Input } from "@/components/ui/input";
import type { UseNursingPatientList } from "@/pages/nursing/composables/useNursingPatientList";

// The composable is passed in as a prop and its form refs are v-model-bound;
// that's the same "composable-as-prop" pattern Reception's PatientSearchPanel
// uses, so prop mutation is intentional here.
/* eslint-disable vue/no-mutating-props -- v-model on the passed-in composable's form refs */

const props = defineProps<{
  list: UseNursingPatientList;
}>();

const { t } = useI18n();

function onRowClick(row: unknown) {
  props.list.selectPatient(row as Parameters<typeof props.list.selectPatient>[0]);
}
</script>

<template>
  <div class="flex flex-1 flex-col overflow-hidden">
    <div class="border-b border-border p-3">
      <Input
        v-model="list.patientSearchQuery.value"
        type="search"
        :placeholder="t('patient.search')"
        :aria-label="t('patient.search')"
        @input="list.onPatientSearchInput"
      />
    </div>

    <!-- Recent & Pinned Patients Quick Bar (Volume 1.3 §9.1) -->
    <div
      v-if="list.recentItems.value && list.recentItems.value.length > 0 && !list.patientSearchQuery.value.trim() && list.patients.value.length > 0"
      class="flex items-center gap-1.5 border-b border-border px-3 py-2 overflow-x-auto no-scrollbar bg-surface/60"
      :aria-label="t('recent.label')"
    >
      <div class="flex items-center gap-1 text-[11px] font-semibold text-muted-foreground uppercase tracking-wider shrink-0 pr-2 border-r border-border">
        <History class="size-3.5 text-muted-foreground" aria-hidden="true" />
        <span>{{ t("recent.label") }}</span>
      </div>
      <div class="flex items-center gap-1.5 shrink-0">
        <div
          v-for="item in list.recentItems.value"
          :key="item.id"
          class="group inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1 text-xs transition-all select-none"
          :class="
            item.id === list.selectedPatient.value?.id
              ? 'bg-accent border-primary/40 font-medium text-accent-foreground shadow-xs'
              : 'bg-card border-border hover:border-primary/40 hover:bg-secondary/60 text-foreground'
          "
          @click="list.openRecentPatient(item.id)"
        >
          <Avatar class="size-4.5 shrink-0">
            <AvatarFallback class="text-[9px] font-semibold bg-primary/10 text-primary">
              {{ list.patientInitials(item.name) }}
            </AvatarFallback>
          </Avatar>
          <span class="truncate max-w-[100px] text-[11.5px]">{{ item.name }}</span>
          <span class="text-[10px] font-mono text-muted-foreground">{{ item.mrn }}</span>
          <button
            type="button"
            class="p-0.5 text-muted-foreground hover:text-primary transition-colors cursor-pointer"
            :class="item.pinned ? 'text-primary' : 'opacity-40 group-hover:opacity-100'"
            @click.stop="list.togglePin(item.id)"
            :aria-label="item.pinned ? t('patient.unpin') : t('patient.pin')"
          >
            <Pin class="size-3" :fill="item.pinned ? 'currentColor' : 'none'" aria-hidden="true" />
          </button>
        </div>
      </div>
    </div>

    <div class="flex-1 overflow-auto">
      <DataTable
        :columns="list.patientColumns.value"
        :rows="list.patients.value"
        :row-key="(r: any) => r.id"
        :active-row-key="list.selectedPatient.value?.id"
        :on-row-click="onRowClick"
        :loading="list.isLoading.value"
        :error="list.error.value"
        :bordered="false"
        :empty-title="t('nursing.no_patients_found')"
      >
        <template #patient-name="{ row }">
          <div class="flex items-center gap-2 py-0.5 min-w-0">
            <Avatar class="size-6.5 shrink-0">
              <AvatarFallback class="text-[10px] font-semibold bg-primary/10 text-primary">
                {{ list.patientInitials(list.patientDisplayName(row)) }}
              </AvatarFallback>
            </Avatar>
            <div class="min-w-0 flex-1 leading-tight">
              <span class="truncate font-medium text-foreground text-[12.5px] block">
                {{ list.patientDisplayName(row) }}
              </span>
            </div>
          </div>
        </template>
      </DataTable>
    </div>
  </div>
</template>
