/**
 * ClinicianPatientListPanel — Patients directory search tab (Volume 2.2 §4.1)
 * ===========================================================================
 * Provides debounced directory search, recent/pinned quick-access bar,
 * and high-density patient lookup for the Clinician workstation.
 */

<script setup lang="ts">
import { History, Pin, Search } from "lucide-vue-next";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import DataTable from "@/components/common/DataTable.vue";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Input } from "@/components/ui/input";
import { patientInitials } from "@/pages/reception/receptionFormatters";
import { usePatientSearch } from "@/pages/reception/composables/usePatientSearch";

const props = defineProps<{
  search: ReturnType<typeof usePatientSearch>;
  onSelectPatient: (patientId: string) => void;
}>();

const { t } = useI18n({ useScope: "global" });
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
    <!-- Search Header -->
    <div class="border-b border-border p-2.5">
      <div class="relative">
        <Input
          id="clinician-patient-search"
          v-model="search.searchQuery.value"
          type="search"
          :placeholder="t('patient.search')"
          :aria-label="t('patient.search')"
          class="h-8 text-xs"
          @input="search.onSearchInput"
        />
      </div>
    </div>

    <!-- Recent & Pinned Patients Quick Bar -->
    <div
      v-if="search.recentItems.value.length > 0 && !search.searchQuery.value.trim() && search.patientRows.value.length > 0"
      class="flex items-center gap-1.5 border-b border-border px-3 py-2 overflow-x-auto no-scrollbar bg-surface/60 shrink-0"
      :aria-label="t('recent.label')"
    >
      <div class="flex items-center gap-1 text-[11px] font-semibold text-muted-foreground uppercase tracking-wider shrink-0 pr-2 border-r border-border">
        <History class="size-3.5 text-muted-foreground" aria-hidden="true" />
        <span>{{ t("recent.label") }}</span>
      </div>
      <div class="flex items-center gap-1.5 shrink-0">
        <div
          v-for="item in search.recentItems.value"
          :key="item.id"
          class="group inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1 text-xs transition-all select-none"
          :class="
            item.id === search.currentPatientId.value
              ? 'bg-accent border-primary/40 font-medium text-accent-foreground shadow-xs'
              : 'bg-card border-border hover:border-primary/40 hover:bg-secondary/60 text-foreground'
          "
          @click="onSelectPatient(item.id)"
        >
          <Avatar class="size-4.5 shrink-0">
            <AvatarFallback class="text-[9px] font-semibold bg-primary/10 text-primary">
              {{ patientInitials(item.name) }}
            </AvatarFallback>
          </Avatar>
          <span class="truncate max-w-[100px] text-[11.5px]">{{ item.name }}</span>
          <span class="text-[10px] font-mono text-muted-foreground">{{ item.mrn }}</span>
          <button
            type="button"
            class="p-0.5 text-muted-foreground hover:text-primary transition-colors cursor-pointer"
            :class="item.pinned ? 'text-primary' : 'opacity-40 group-hover:opacity-100'"
            @click.stop="search.togglePin(item.id)"
            :aria-label="item.pinned ? t('patient.unpin') : t('patient.pin')"
          >
            <Pin class="size-3" :fill="item.pinned ? 'currentColor' : 'none'" aria-hidden="true" />
          </button>
        </div>
      </div>
    </div>

    <!-- Patients Results Table -->
    <div class="flex-1 overflow-hidden">
      <DataTable
        :rows="search.patientRows.value"
        :columns="search.patientColumns.value"
        :row-key="(r) => r.id"
        :active-row-key="search.currentPatientId.value"
        :loading="search.isSearching.value"
        :error="search.searchError.value"
        :bordered="false"
        :empty-title="t('common.no_data')"
        :empty-description="t('patient.empty_hint')"
        persist-key="clinician-patients-table"
        @row-click="(row) => onSelectPatient(row.id)"
        @retry="search.handlePatientRetry"
      >
        <template #patient-name="{ row }">
          <div class="flex items-center gap-2 py-0.5 min-w-0">
            <Avatar class="size-6 shrink-0">
              <AvatarFallback class="text-[10px] font-semibold bg-primary/10 text-primary">
                {{ patientInitials(row.name) }}
              </AvatarFallback>
            </Avatar>
            <div class="min-w-0 flex-1 leading-tight">
              <span class="truncate font-medium text-foreground text-[12.5px] block">
                {{ row.name }}
              </span>
            </div>
          </div>
        </template>
      </DataTable>
    </div>
  </div>
</template>
