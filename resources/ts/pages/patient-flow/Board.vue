/**
 * Patient Flow Board Page (Phase 4 of queue-based-workflow-modernization-plan)
 * ============================================================================
 * Full-facility overview of patient journeys across all clinical stages.
 */

<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { Activity } from "lucide-vue-next";
import { useI18n } from "vue-i18n";

const { t } = useI18n({ useScope: "global" });
</script>

<template>
  <Head title="Patient Flow Board" />

  <div class="flex h-screen w-full flex-col bg-background text-foreground">
    <header class="flex h-14 items-center justify-between border-b border-border bg-surface px-6">
      <div class="flex items-center gap-2.5">
        <Activity class="size-5 text-primary" aria-hidden="true" />
        <h1 class="text-base font-bold text-foreground">
          Patient Flow Board
        </h1>
      </div>
    </header>

    <main class="flex-1 overflow-auto p-6">
      <div class="rounded-xl border border-border bg-surface p-6 shadow-2xs">
        <p class="text-sm text-muted-foreground">
          Real-time patient flow journey board across facility service points.
        </p>
      </div>
    </main>
  </div>
</template>
