<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { ArrowRight, HeartPulse, Sparkles } from "lucide-vue-next";
import { Button } from "@/components/ui/button";

const emit = defineEmits<{
  (e: "open-demo"): void;
}>();

const { t } = useI18n({ useScope: "global" });
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div
      class="relative rounded-3xl border border-border bg-gradient-to-r from-teal-900/30 via-card to-cyan-900/20 p-8 sm:p-12 lg:p-16 shadow-lg overflow-hidden text-center space-y-6"
    >
      <!-- Background Ambient Glow -->
      <div
        class="pointer-events-none absolute -bottom-24 -left-24 h-72 w-72 rounded-full bg-teal-500/10 blur-3xl"
        aria-hidden="true"
      />
      <div
        class="pointer-events-none absolute -top-24 -right-24 h-72 w-72 rounded-full bg-cyan-500/10 blur-3xl"
        aria-hidden="true"
      />

      <!-- Badge -->
      <div
        class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 border border-primary/20 px-3.5 py-1 text-xs font-semibold text-primary"
      >
        <Sparkles class="h-3.5 w-3.5" />
        <span>{{ t("landing.cta_badge") }}</span>
      </div>

      <!-- Headline -->
      <h2
        class="text-3xl font-extrabold tracking-tight text-foreground sm:text-4xl lg:text-5xl max-w-3xl mx-auto leading-tight"
      >
        {{ t("landing.cta_title") }}
      </h2>

      <!-- Subtitle -->
      <p
        class="text-base text-muted-foreground sm:text-lg max-w-2xl mx-auto leading-relaxed"
      >
        {{ t("landing.cta_subtitle") }}
      </p>

      <!-- Buttons -->
      <div class="flex flex-wrap items-center justify-center gap-4 pt-2">
        <Button
          size="lg"
          class="h-12 gap-2 px-8 text-sm font-bold shadow-lg shadow-teal-500/20 cursor-pointer bg-primary text-primary-foreground hover:bg-primary/90"
          @click="emit('open-demo')"
        >
          <span>{{ t("landing.cta_demo_btn") }}</span>
          <ArrowRight class="h-4 w-4" />
        </Button>

        <Link href="/login">
          <Button
            variant="outline"
            size="lg"
            class="h-12 gap-2 px-7 text-sm font-semibold cursor-pointer border-border hover:bg-card"
          >
            <span>{{ t("landing.cta_signin_btn") }}</span>
            <ArrowRight class="h-4 w-4" />
          </Button>
        </Link>
      </div>
    </div>
  </section>
</template>
