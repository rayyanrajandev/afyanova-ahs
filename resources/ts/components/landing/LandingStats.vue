<script setup lang="ts">
import { useI18n } from "vue-i18n";
import {
  Activity,
  Layers,
  Server,
  ShieldCheck,
} from "lucide-vue-next";

const { t } = useI18n({ useScope: "global" });
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <!-- Metric 1: Uptime -->
      <div
        class="rounded-2xl border border-border bg-card p-5 sm:p-6 shadow-2xs space-y-2 hover:border-primary/40 transition-colors"
      >
        <div
          class="flex items-center justify-between text-xs text-muted-foreground"
        >
          <span class="font-medium uppercase tracking-wider text-[11px]">{{
            t("landing.stats_uptime_label")
          }}</span>
          <div class="p-2 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400">
            <Server class="h-4 w-4" />
          </div>
        </div>
        <div
          class="text-3xl sm:text-4xl font-extrabold tracking-tight text-foreground font-mono"
        >
          {{ t("landing.stats_uptime") }}
        </div>
        <p class="text-xs text-muted-foreground leading-relaxed pt-1">
          {{ t("landing.stats_uptime_desc") }}
        </p>
      </div>

      <!-- Metric 2: Edge Sync Latency -->
      <div
        class="rounded-2xl border border-border bg-card p-5 sm:p-6 shadow-2xs space-y-2 hover:border-primary/40 transition-colors"
      >
        <div
          class="flex items-center justify-between text-xs text-muted-foreground"
        >
          <span class="font-medium uppercase tracking-wider text-[11px]">{{
            t("landing.stats_sync_label")
          }}</span>
          <div class="p-2 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400">
            <Activity class="h-4 w-4" />
          </div>
        </div>
        <div
          class="text-3xl sm:text-4xl font-extrabold tracking-tight text-foreground font-mono"
        >
          {{ t("landing.stats_sync") }}
        </div>
        <p class="text-xs text-muted-foreground leading-relaxed pt-1">
          {{ t("landing.stats_sync_desc") }}
        </p>
      </div>

      <!-- Metric 3: National Standards -->
      <div
        class="rounded-2xl border border-border bg-card p-5 sm:p-6 shadow-2xs space-y-2 hover:border-primary/40 transition-colors"
      >
        <div
          class="flex items-center justify-between text-xs text-muted-foreground"
        >
          <span class="font-medium uppercase tracking-wider text-[11px]">{{
            t("landing.stats_standards_label")
          }}</span>
          <div class="p-2 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
            <ShieldCheck class="h-4 w-4" />
          </div>
        </div>
        <div
          class="text-3xl sm:text-4xl font-extrabold tracking-tight text-foreground font-mono"
        >
          {{ t("landing.stats_standards") }}
        </div>
        <p class="text-xs text-muted-foreground leading-relaxed pt-1">
          {{ t("landing.stats_standards_desc") }}
        </p>
      </div>

      <!-- Metric 4: Workspaces -->
      <div
        class="rounded-2xl border border-border bg-card p-5 sm:p-6 shadow-2xs space-y-2 hover:border-primary/40 transition-colors"
      >
        <div
          class="flex items-center justify-between text-xs text-muted-foreground"
        >
          <span class="font-medium uppercase tracking-wider text-[11px]">{{
            t("landing.stats_workspaces_label")
          }}</span>
          <div class="p-2 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
            <Layers class="h-4 w-4" />
          </div>
        </div>
        <div
          class="text-3xl sm:text-4xl font-extrabold tracking-tight text-foreground font-mono"
        >
          {{ t("landing.stats_workspaces") }}
        </div>
        <p class="text-xs text-muted-foreground leading-relaxed pt-1">
          {{ t("landing.stats_workspaces_desc") }}
        </p>
      </div>
    </div>
  </section>
</template>
