/**
 * Landing Layout (2027 Enterprise Edition)
 * =========================================
 * Clean public layout: sticky nav, content slot, minimal footer.
 */

<script setup lang="ts">
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { ArrowRight, HeartPulse, Moon, Sun } from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { setLocale } from "@/i18n";
import { useUiStore } from "@/stores/uiStore";

const { t, locale } = useI18n({ useScope: "global" });
const uiStore = useUiStore();

const isDark = computed(() => uiStore.theme === "dark");

function toggleTheme() {
  uiStore.setTheme(isDark.value ? "light" : "dark");
}

function switchLocale(next: "en" | "sw") {
  locale.value = next;
  setLocale(next);
  uiStore.setLocale(next);
}
</script>

<template>
  <div class="min-h-screen bg-background text-foreground flex flex-col">
    <!-- Sticky Nav -->
    <header class="sticky top-0 z-50 w-full border-b border-border/60 bg-background/90 backdrop-blur-md">
      <div class="mx-auto flex h-14 max-w-5xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <!-- Brand -->
        <Link href="/" class="flex items-center gap-2.5 group">
          <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-cyan-500 to-teal-600 text-white shadow-sm transition-transform group-hover:scale-105">
            <HeartPulse class="h-4 w-4" />
          </div>
          <span class="text-base font-bold tracking-tight text-foreground">AFYANOVA</span>
          <span class="hidden sm:inline rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold text-primary">AHS</span>
        </Link>

        <!-- Nav links (desktop) -->
        <nav class="hidden md:flex items-center gap-5 text-xs font-medium text-muted-foreground">
          <a href="#workspaces" class="hover:text-foreground transition-colors">{{ t('landing.workspaces_title') }}</a>
        </nav>

        <!-- Right controls -->
        <div class="flex items-center gap-2">
          <div class="inline-flex items-center rounded-lg border border-border bg-card p-0.5 text-xs shadow-xs">
            <button
              type="button"
              class="rounded-md px-2 py-1 font-medium transition-colors cursor-pointer"
              :class="locale === 'en' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
              @click="switchLocale('en')"
            >EN</button>
            <button
              type="button"
              class="rounded-md px-2 py-1 font-medium transition-colors cursor-pointer"
              :class="locale === 'sw' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
              @click="switchLocale('sw')"
            >SW</button>
          </div>
          <button
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-card text-muted-foreground hover:bg-accent hover:text-accent-foreground shadow-xs cursor-pointer transition-colors"
            @click="toggleTheme"
          >
            <Sun v-if="isDark" class="h-4 w-4" />
            <Moon v-else class="h-4 w-4" />
          </button>
          <Link href="/login">
            <Button size="sm" class="h-8 gap-1.5 px-3 text-xs font-medium shadow-xs cursor-pointer">
              {{ t('auth.sign_in') }}
              <ArrowRight class="h-3 w-3" />
            </Button>
          </Link>
        </div>
      </div>
    </header>

    <!-- Content -->
    <main class="flex-1">
      <slot />
    </main>

    <!-- Footer -->
    <footer class="border-t border-border/50 py-6">
      <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-muted-foreground">
        <div class="flex items-center gap-2">
          <HeartPulse class="h-3.5 w-3.5 text-primary" />
          <span>{{ t('landing.footer_rights') }}</span>
        </div>
        <div class="flex items-center gap-3">
          <span>HL7 FHIR R4</span>
          <span class="text-border">·</span>
          <span>{{ t('landing.footer_facility_tag') }}</span>
          <span class="text-border">·</span>
          <span>{{ t('landing.footer_version') }}</span>
        </div>
      </div>
    </footer>
  </div>
</template>
