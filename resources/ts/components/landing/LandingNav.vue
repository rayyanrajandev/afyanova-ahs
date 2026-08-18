<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from "vue";
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  ArrowRight,
  HeartPulse,
  Menu,
  Moon,
  Sun,
  X,
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { setLocale } from "@/i18n";
import { useUiStore } from "@/stores/uiStore";

const emit = defineEmits<{
  (e: "open-demo"): void;
}>();

const { t, locale } = useI18n({ useScope: "global" });
const uiStore = useUiStore();

const isDark = computed(() => uiStore.theme === "dark");
const isMobileMenuOpen = ref(false);
const isScrolled = ref(false);

function toggleTheme() {
  uiStore.setTheme(isDark.value ? "light" : "dark");
}

function switchLocale(next: "en" | "sw") {
  locale.value = next;
  setLocale(next);
  uiStore.setLocale(next);
}

function handleScroll() {
  if (typeof window !== "undefined") {
    isScrolled.value = window.scrollY > 20;
  }
}

onMounted(() => {
  if (typeof window !== "undefined") {
    window.addEventListener("scroll", handleScroll, { passive: true });
    handleScroll();
  }
});

onUnmounted(() => {
  if (typeof window !== "undefined") {
    window.removeEventListener("scroll", handleScroll);
  }
});

function handleNavClick() {
  isMobileMenuOpen.value = false;
}
</script>

<template>
  <!-- Accessible Skip Link -->
  <a
    href="#main-content"
    class="sr-only-focusable fixed top-3 left-3 z-[100] rounded-md bg-primary px-4 py-2 text-xs font-semibold text-primary-foreground shadow-lg ring-2 ring-ring focus:not-sr-only"
  >
    {{ t("landing.skip_to_content") }}
  </a>

  <!-- Sticky Glass Header -->
  <header
    class="sticky top-0 z-50 w-full transition-all duration-200"
    :class="
      isScrolled
        ? 'bg-background/95 backdrop-blur-md border-b border-border/80 shadow-xs py-3'
        : 'bg-background/85 backdrop-blur-md border-b border-border/40 py-4'
    "
  >
    <div
      class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8"
    >
      <!-- Brand Logo -->
      <Link
        href="/"
        class="flex items-center gap-3 group cursor-pointer shrink-0"
      >
        <div
          class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-cyan-600 text-white shadow-sm transition-transform group-hover:scale-105 shrink-0"
        >
          <HeartPulse class="h-5 w-5" />
        </div>
        <div class="flex items-baseline gap-1.5">
          <span
            class="text-base font-extrabold tracking-tight text-foreground whitespace-nowrap"
            >AFYANOVA</span
          >
          <span
            class="rounded bg-teal-500/10 px-1.5 py-0.5 text-[10px] font-bold text-teal-600 dark:text-teal-400"
            >AHS</span
          >
        </div>
      </Link>

      <!-- Desktop Navigation Links -->
      <nav
        class="hidden xl:flex items-center gap-6 2xl:gap-8 text-xs lg:text-sm font-medium text-muted-foreground whitespace-nowrap shrink-0"
        aria-label="Main Navigation"
      >
        <a
          href="#workspaces"
          class="hover:text-foreground transition-colors whitespace-nowrap"
          >{{ t("landing.nav_workspaces") }}</a
        >
        <a
          href="#journey"
          class="hover:text-foreground transition-colors whitespace-nowrap"
          >{{ t("landing.nav_journey") }}</a
        >
        <a
          href="#resilience"
          class="hover:text-foreground transition-colors whitespace-nowrap"
          >{{ t("landing.nav_resilience") }}</a
        >
        <a
          href="#integrations"
          class="hover:text-foreground transition-colors whitespace-nowrap"
          >{{ t("landing.nav_integrations") }}</a
        >
        <a
          href="#security"
          class="hover:text-foreground transition-colors whitespace-nowrap"
          >{{ t("landing.nav_security") }}</a
        >
      </nav>

      <!-- Right Controls & Actions -->
      <div class="flex items-center gap-2 sm:gap-3 shrink-0">
        <!-- Language Switcher -->
        <div
          class="inline-flex items-center rounded-lg border border-border/70 bg-card p-0.5 text-xs shadow-2xs shrink-0"
          role="group"
          aria-label="Language Selector"
        >
          <button
            type="button"
            class="rounded-md px-2 py-1 font-semibold transition-colors cursor-pointer"
            :class="
              locale === 'en'
                ? 'bg-primary text-primary-foreground shadow-2xs'
                : 'text-muted-foreground hover:text-foreground'
            "
            :aria-pressed="locale === 'en'"
            @click="switchLocale('en')"
          >
            EN
          </button>
          <button
            type="button"
            class="rounded-md px-2 py-1 font-semibold transition-colors cursor-pointer"
            :class="
              locale === 'sw'
                ? 'bg-primary text-primary-foreground shadow-2xs'
                : 'text-muted-foreground hover:text-foreground'
            "
            :aria-pressed="locale === 'sw'"
            @click="switchLocale('sw')"
          >
            SW
          </button>
        </div>

        <!-- Theme Toggle -->
        <button
          type="button"
          class="inline-flex h-8.5 w-8.5 items-center justify-center rounded-lg border border-border/70 bg-card text-muted-foreground hover:text-foreground shadow-2xs cursor-pointer transition-colors focus-ring shrink-0"
          :title="isDark ? 'Switch to Light Theme' : 'Switch to Dark Theme'"
          :aria-label="
            isDark ? 'Switch to Light Theme' : 'Switch to Dark Theme'
          "
          @click="toggleTheme"
        >
          <Sun v-if="isDark" class="h-4 w-4" />
          <Moon v-else class="h-4 w-4" />
        </button>

        <!-- Sign In -->
        <Link href="/login" class="hidden sm:inline-flex shrink-0">
          <Button
            variant="ghost"
            size="sm"
            class="h-9 text-xs font-semibold cursor-pointer whitespace-nowrap"
          >
            {{ t("landing.nav_sign_in") }}
          </Button>
        </Link>

        <!-- Request Demo CTA -->
        <Button
          size="sm"
          class="h-9 gap-1.5 px-3.5 sm:px-4 text-xs font-bold shadow-sm cursor-pointer bg-primary text-primary-foreground hover:bg-primary/90 whitespace-nowrap shrink-0"
          @click="emit('open-demo')"
        >
          <span>{{ t("landing.nav_request_demo") }}</span>
          <ArrowRight class="h-3.5 w-3.5" />
        </Button>

        <!-- Mobile Menu Toggle -->
        <button
          type="button"
          class="xl:hidden inline-flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-card text-foreground cursor-pointer shrink-0"
          aria-label="Toggle Navigation Menu"
          @click="isMobileMenuOpen = !isMobileMenuOpen"
        >
          <X v-if="isMobileMenuOpen" class="h-4 w-4" />
          <Menu v-else class="h-4 w-4" />
        </button>
      </div>
    </div>

    <!-- Mobile Drawer -->
    <div
      v-if="isMobileMenuOpen"
      class="xl:hidden border-b border-border bg-background p-5 space-y-4 shadow-xl animate-in slide-in-from-top-3"
    >
      <nav class="grid grid-cols-2 gap-2 text-xs font-semibold">
        <a
          href="#workspaces"
          @click="handleNavClick"
          class="p-2.5 rounded-lg bg-card border border-border hover:text-primary"
        >
          {{ t("landing.nav_workspaces") }}
        </a>
        <a
          href="#journey"
          @click="handleNavClick"
          class="p-2.5 rounded-lg bg-card border border-border hover:text-primary"
        >
          {{ t("landing.nav_journey") }}
        </a>
        <a
          href="#resilience"
          @click="handleNavClick"
          class="p-2.5 rounded-lg bg-card border border-border hover:text-primary"
        >
          {{ t("landing.nav_resilience") }}
        </a>
        <a
          href="#integrations"
          @click="handleNavClick"
          class="p-2.5 rounded-lg bg-card border border-border hover:text-primary"
        >
          {{ t("landing.nav_integrations") }}
        </a>
        <a
          href="#security"
          @click="handleNavClick"
          class="p-2.5 rounded-lg bg-card border border-border hover:text-primary"
        >
          {{ t("landing.nav_security") }}
        </a>
      </nav>

      <div class="flex flex-col gap-2 pt-2 border-t border-border">
        <Button
          @click="
            emit('open-demo');
            isMobileMenuOpen = false;
          "
          class="w-full text-xs font-bold"
        >
          {{ t("landing.nav_request_demo") }}
        </Button>
        <Link href="/login" class="w-full">
          <Button variant="outline" class="w-full text-xs font-semibold">
            {{ t("landing.nav_sign_in") }}
          </Button>
        </Link>
      </div>
    </div>
  </header>
</template>
