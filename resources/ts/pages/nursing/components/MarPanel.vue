/**
 * MarPanel — Nursing detail pane MAR (Volume 2.3 §4.3, §8, Volume 3.8 Phase 6)
 * =========================================================================
 * Extracted from nursing/Index.vue (2026-08-13, component decomposition —
 * Reception-style separation of concerns). Renders the medication
 * administration record for the open patient. Pure presentation: state/logic
 * lives in `useMar`, passed in as a prop.
 */

<script setup lang="ts">
import { useI18n } from "vue-i18n";
import Alert from "@/components/common/Alert.vue";
import EmptyState from "@/components/common/EmptyState.vue";
import StatusBadge from "@/components/common/StatusBadge.vue";
import { Button } from "@/components/ui/button";
import type { UseMar } from "@/pages/nursing/composables/useMar";

defineProps<{
  mar: UseMar;
}>();

const emit = defineEmits<{
  close: [];
}>();

const { t } = useI18n();
</script>

<template>
  <aside class="flex w-96 flex-col rounded-lg border border-border bg-surface">
    <div class="flex items-center justify-between border-b border-border px-4 py-3">
      <h3 class="text-sm font-semibold text-foreground">{{ t("nursing.mar") }}</h3>
      <Button size="sm" variant="ghost" @click="emit('close')">{{ t("common.close") }}</Button>
    </div>
    <div class="flex-1 overflow-auto p-4 space-y-4">
      <Alert
        variant="info"
        :title="t('nursing.mar_administer_unavailable_title')"
        :description="t('nursing.mar_administer_unavailable_description')"
      />

      <!-- Skeleton Loader -->
      <div v-if="mar.isLoading.value" class="space-y-2">
        <div
          v-for="n in 3"
          :key="n"
          class="rounded-lg border border-border bg-card p-3 space-y-2 animate-pulse"
        >
          <div class="flex items-center justify-between">
            <div class="h-4 w-32 rounded bg-secondary/80" />
            <div class="h-4 w-16 rounded bg-secondary/80" />
          </div>
          <div class="flex items-center gap-3">
            <div class="h-3 w-12 rounded bg-secondary/60" />
            <div class="h-3 w-16 rounded bg-secondary/60" />
            <div class="h-3 w-20 rounded bg-secondary/60" />
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <EmptyState
        v-else-if="mar.mar.value.length === 0"
        illustration="inbox"
        :title="t('nursing.mar_empty')"
      />

      <!-- Medication Cards List -->
      <div v-else class="space-y-2">
        <div v-for="med in mar.mar.value" :key="med.id" class="rounded-lg border border-border bg-card p-3 transition-all hover:bg-accent/50">
          <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-foreground">{{ med.name }}</span>
            <StatusBadge :status="mar.marStatusVariant(med.status)" />
          </div>
          <div class="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
            <span class="clinical-value font-mono">{{ med.dose }}</span>
            <span>{{ med.route }}</span>
            <span>{{ med.frequency }}</span>
          </div>
        </div>
      </div>
    </div>
  </aside>
</template>
