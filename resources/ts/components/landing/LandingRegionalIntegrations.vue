<script setup lang="ts">
import { useI18n } from "vue-i18n";
import {
  CreditCard,
  Fingerprint,
  Pill,
  Receipt,
  Sparkles,
  Wallet,
} from "lucide-vue-next";

const { t } = useI18n({ useScope: "global" });

const integrations = [
  {
    key: "nhif",
    icon: CreditCard,
    color: "text-emerald-600 dark:text-emerald-400",
    bg: "bg-emerald-500/10",
    tag: "e-Claims & Tariffs",
  },
  {
    key: "gepg",
    icon: Wallet,
    color: "text-blue-600 dark:text-blue-400",
    bg: "bg-blue-500/10",
    tag: "Control Numbers & M-Pesa",
  },
  {
    key: "nemlit",
    icon: Pill,
    color: "text-indigo-600 dark:text-indigo-400",
    bg: "bg-indigo-500/10",
    tag: "2024 Drug Formulary",
  },
  {
    key: "nida",
    icon: Fingerprint,
    color: "text-teal-600 dark:text-teal-400",
    bg: "bg-teal-500/10",
    tag: "Biometrics & MPI",
  },
];
</script>

<template>
  <section id="integrations" class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-12 scroll-mt-24">
    <!-- Header -->
    <div class="text-center space-y-3 max-w-3xl mx-auto">
      <div
        class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-0.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400"
      >
        <Sparkles class="h-3 w-3" />
        <span>{{ t("landing.integrations_badge") }}</span>
      </div>
      <h2
        class="text-3xl font-extrabold tracking-tight text-foreground sm:text-4xl"
      >
        {{ t("landing.integrations_title") }}
      </h2>
      <p class="text-base text-muted-foreground leading-relaxed">
        {{ t("landing.integrations_subtitle") }}
      </p>
    </div>

    <!-- 4-Card Integration Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
      <div
        v-for="item in integrations"
        :key="item.key"
        class="rounded-2xl border border-border bg-card p-6 shadow-2xs space-y-4 hover:border-primary/40 transition-colors flex flex-col justify-between"
      >
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <div
              class="flex h-11 w-11 items-center justify-center rounded-xl"
              :class="item.bg"
            >
              <component :is="item.icon" class="h-5 w-5" :class="item.color" />
            </div>
            <span
              class="rounded bg-muted px-2 py-0.5 text-[10px] font-mono font-bold text-muted-foreground"
            >
              {{ item.tag }}
            </span>
          </div>

          <h3 class="text-lg font-bold text-foreground">
            {{ t(`landing.integrations_${item.key}_title`) }}
          </h3>

          <p class="text-xs text-muted-foreground leading-relaxed">
            {{ t(`landing.integrations_${item.key}_desc`) }}
          </p>
        </div>

        <div class="pt-2 border-t border-border/50 flex items-center gap-1.5 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
          <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />
          <span>Production Ready Integration</span>
        </div>
      </div>
    </div>
  </section>
</template>
