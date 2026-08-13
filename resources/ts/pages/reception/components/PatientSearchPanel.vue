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
import { History, Pin } from "lucide-vue-next";
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
  <!-- Search input -->
  <div class="border-b border-border p-3">
    <Input
      id="reception-patient-search"
      v-model="search.searchQuery.value"
      type="search"
      :placeholder="t('patient.search')"
      :aria-label="t('patient.search')"
      @input="search.onSearchInput"
    />
  </div>

  <!-- Recent patients (Volume 1.3 §9.1 — Volume 3.7 T2.8). Now hidden
       once a search query is typed (2026-08-11, direct user feedback) —
       it used to stay put regardless, so it sat above/alongside live
       search results the moment you started typing something else,
       competing for space and still showing an unrelated patient while
       you were mid-search. This is a "quick access before you've
       decided what to search for" list, not a permanent fixture — it
       should get out of the way the instant there's an actual query. -->
  <div
    v-if="search.recentItems.value.length > 0 && !search.searchQuery.value.trim()"
    class="max-h-40 overflow-y-auto border-b border-border p-3"
    :aria-label="t('recent.label')"
  >
    <h3
      class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"
    >
      <History class="h-3.5 w-3.5" aria-hidden="true" />
      {{ t("recent.label") }}
    </h3>
    <ul class="space-y-1">
      <li
        v-for="item in search.recentItems.value"
        :key="item.id"
        class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1 text-sm hover:bg-surface-hover"
        @click="search.openRecentPatient(item.id)"
      >
        <Avatar class="size-6 shrink-0">
          <AvatarFallback class="text-[10px]">
            {{ patientInitials(item.name) }}
          </AvatarFallback>
        </Avatar>
        <span class="min-w-0 flex-1">
          <span class="block truncate font-medium text-foreground">{{
            item.name
          }}</span>
          <span class="clinical-value block truncate text-xs text-muted-foreground">
            {{ item.mrn }}
          </span>
        </span>
        <!-- Icon-only here (unlike its labeled sibling in PatientProfileView's
             header — this compact row has no room for the text), so it gets
             a Tooltip to compensate (workspace tooltip audit, 2026-08-11). -->
        <Tooltip>
          <TooltipTrigger as-child>
            <Button
              variant="ghost"
              size="sm"
              class="h-6 w-6 shrink-0 p-0"
              :class="item.pinned ? 'text-primary' : 'text-muted-foreground'"
              @click.stop="search.togglePin(item.id)"
              :aria-label="item.pinned ? t('patient.unpin') : t('patient.pin')"
            >
              <Pin class="h-3.5 w-3.5" :fill="item.pinned ? 'currentColor' : 'none'" aria-hidden="true" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>{{ item.pinned ? t("patient.unpin") : t("patient.pin") }}</TooltipContent>
        </Tooltip>
      </li>
    </ul>
  </div>

  <!-- Results (Volume 1.2 §6 — DataTable with the four states) -->
  <div class="flex-1 overflow-hidden">
    <DataTable
      :rows="search.patientRows.value"
      :columns="search.patientColumns.value"
      :row-key="(r) => r.id"
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
        <!-- `size-6` (component-library audit, 2026-08-10): this row sits
             inside DataTable's own fixed row height, tightened the same
             day (32px comfortable, was 36px) — the previous 28px avatar
             left only ~2px total top/bottom margin, visibly touching the
             row border. The recent-patients list above uses the same
             `size-6` now too, not because its own `py-1`-padded row needed
             it, but so a patient avatar is the same size everywhere in
             this tab, not smaller in one list and bigger in the other. -->
        <div class="flex items-center gap-2">
          <Avatar class="size-6 shrink-0">
            <AvatarFallback class="text-[10px]">
              {{ patientInitials(row.name) }}
            </AvatarFallback>
          </Avatar>
          <span class="truncate font-medium text-foreground">{{ row.name }}</span>
        </div>
      </template>
    </DataTable>
  </div>
</template>
