/**
 * PatientSearchPanel — context-pane "Patients" tab content (Volume 2.1
 * §7.2, Volume 1.3 §6.3/§9.1, Volume 1.2 §6)
 * ==========================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit).
 * Pure template extraction — search box, recent/pinned quick-list, and the
 * results DataTable, unchanged.
 *
 * Only calls into `search`'s functions and reads its refs; never assigns
 * through the prop path, so it does NOT need `vue/no-mutating-props`
 * disabled — except `searchQuery`, which is `v-model`-bound (the Input is a
 * two-way binding onto `search.searchQuery.value`), same reasoning as
 * CancelQueueItemDialog.vue's `cancelReason`.
 *
 * Pin icon fixed (2026-08-11, direct user feedback: "too light and lacks
 *   visual weight"). The pinned state used `text-accent` — measured live,
 *   that resolves to `oklch(0.93 0.02 210)`, a near-white *background*
 *   tint token (meant to pair with `--accent-foreground` as text sitting
 *   on top of it), not a text/icon color on its own. Against this app's
 *   `--surface`/`--background` (0.98–1.0 lightness), the icon was
 *   effectively invisible at rest — confirmed by screenshot, not just
 *   the token math. Swapped for `text-primary`, the color this app
 *   already uses everywhere else for "this is the active/selected
 *   state" (priority chips, active tab). Also added a `fill` toggle —
 *   solid when pinned, outline when not — so the state reads from shape
 *   too, not color alone (same idiom as GitHub/Twitter's pin-filled
 *   pattern), and bumped `h-3 w-3` to `h-3.5 w-3.5` to match every
 *   other icon already in this size class app-wide (the sibling
 *   Check-in/Schedule icons in PatientProfileView's header are already
 *   `h-3.5 w-3.5` — Pin was the one outlier at 12px instead of 14px).
 */

<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- v-model="search.searchQuery.value", see file header docblock */
import { History, Pin, UserPlus } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import DataTable from "@/components/common/DataTable.vue";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { patientInitials } from "@/pages/reception/receptionFormatters";
import { useSyncStore } from "@/stores/syncStore";
import type { usePatientSearch } from "../composables/usePatientSearch";

defineProps<{
  search: ReturnType<typeof usePatientSearch>;
  openRegistration: () => void;
}>();

const { t } = useI18n();
const syncStore = useSyncStore();
</script>

<template>
  <!-- Search input + Quick Register Action -->
  <div class="border-b border-border p-2.5 flex items-center gap-2">
    <div class="relative flex-1">
      <Input
        id="reception-patient-search"
        v-model="search.searchQuery.value"
        type="search"
        :placeholder="t('patient.search')"
        :aria-label="t('patient.search')"
        class="h-8 text-xs"
        @input="search.onSearchInput"
      />
    </div>
    <Button
      size="sm"
      class="h-8 shrink-0 gap-1.5 px-2.5 text-xs font-medium cursor-pointer shadow-xs"
      :title="t('registration.title') || 'Register New Patient'"
      @click="openRegistration"
    >
      <UserPlus class="size-3.5" aria-hidden="true" />
      <span>{{ t("common.add") }}</span>
    </Button>
  </div>

  <!-- Recent & Pinned Patients Quick Bar (Volume 1.3 §9.1) -->
  <div
    v-if="search.recentItems.value.length > 0 && !search.searchQuery.value.trim() && search.patientRows.value.length > 0"
    class="flex items-center gap-1.5 border-b border-border px-3 py-2 overflow-x-auto no-scrollbar bg-surface/60"
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
        @click="search.openRecentPatient(item.id)"
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

  <!-- Results (Volume 1.2 §6 — DataTable with the four states) -->
  <div class="flex-1 overflow-hidden">
    <DataTable
      :rows="search.patientRows.value"
      :columns="search.patientColumns.value"
      :row-key="(r) => r.id"
      :active-row-key="search.currentPatientId.value"
      :loading="search.isSearching.value"
      :error="search.searchError.value"
      :offline="!syncStore.isOnline"
      :bordered="false"
      :empty-title="t('common.no_data')"
      :empty-description="
        search.searchQuery.value ? t('queue.empty_hint') : t('patient.empty_hint')
      "
      :empty-action-label="t('patient.register')"
      persist-key="reception-patients"
      @row-click="search.handlePatientRowClick"
      @empty-action="openRegistration"
      @retry="search.handlePatientRetry"
    >
      <template #patient-name="{ row }">
        <div class="flex items-center gap-2 py-0.5 min-w-0">
          <Avatar class="size-6.5 shrink-0">
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
</template>
