/**
 * TaskQueuePanel — Nursing context-pane "Tasks" tab (Volume 2.3 §4.1, §9)
 * =========================================================================
 * Extracted from nursing/Index.vue (2026-08-13, component decomposition —
 * Reception-style separation of concerns). Renders the nurse task queue.
 * Pure presentation: mapping/state lives in `useNursingTasks`, passed in as
 * a prop.
 */

<script setup lang="ts">
import { useI18n } from "vue-i18n";
import Queue from "@/components/common/Queue.vue";
import type { UseNursingTasks } from "@/pages/nursing/composables/useNursingTasks";

defineProps<{
  tasks: UseNursingTasks;
}>();

const { t } = useI18n();
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
    <div class="min-h-0 flex-1">
      <Queue
        :items="tasks.taskQueue.value"
        :loading="tasks.isLoading.value"
        :error="tasks.error.value"
        :persistence-key="'afyanova:nursing:task-filters'"
        :empty-title="t('nursing.empty_tasks_title')"
        :empty-description="t('nursing.empty_tasks_desc')"
        :empty-illustration="'clipboard'"
        :empty-badge="t('nursing.station_clear')"
        hide-priority-chips
        @open="tasks.handleTaskOpen"
        @retry="tasks.refetchTasks"
        @empty-action="tasks.refetchTasks"
      />
    </div>
  </div>
</template>
