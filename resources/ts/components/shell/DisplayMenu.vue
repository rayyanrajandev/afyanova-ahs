<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import {
  Check,
  ChevronDown,
  Monitor,
  Moon,
  Rows3,
  SlidersHorizontal,
  Sun,
} from "lucide-vue-next";
import { useUiStore, type DensityName, type ThemeName } from "@/stores/uiStore";

const { t } = useI18n();
const uiStore = useUiStore();
const open = ref(false);
const menuRef = ref<HTMLElement | null>(null);

const densityOptions: {
  key: DensityName;
  labelKey: string;
  hintKey: string;
}[] = [
  {
    key: "compact",
    labelKey: "shell.density_compact",
    hintKey: "shell.density_compact_hint",
  },
  {
    key: "comfortable",
    labelKey: "shell.density_comfortable",
    hintKey: "shell.density_comfortable_hint",
  },
  {
    key: "spacious",
    labelKey: "shell.density_spacious",
    hintKey: "shell.density_spacious_hint",
  },
];

const themeOptions: {
  key: ThemeName;
  labelKey: string;
  icon: typeof Sun;
}[] = [
  { key: "light", labelKey: "shell.theme_light", icon: Sun },
  { key: "dark", labelKey: "shell.theme_dark", icon: Moon },
  { key: "system", labelKey: "shell.theme_system", icon: Monitor },
];

const activeDensityLabel = computed(() => {
  const match = densityOptions.find((d) => d.key === uiStore.density);
  return match ? t(match.labelKey) : t("shell.display");
});

function toggleMenu() {
  open.value = !open.value;
}

function selectDensity(key: DensityName) {
  uiStore.setDensity(key);
}

function selectTheme(key: ThemeName) {
  uiStore.setTheme(key);
}

function resetPanelWidths() {
  for (let i = localStorage.length - 1; i >= 0; i--) {
    const key = localStorage.key(i);
    if (
      key &&
      (key.startsWith("afyanova:splitpane:") ||
        key.startsWith("afyanova:split-") ||
        key.startsWith("afya.split"))
    ) {
      localStorage.removeItem(key);
    }
  }
  window.dispatchEvent(new CustomEvent("afyanova:reset-layout"));
  window.dispatchEvent(new CustomEvent("afyanova:reset-split-panes"));
  open.value = false;
}

function handleClickOutside(event: MouseEvent) {
  if (menuRef.value && !menuRef.value.contains(event.target as Node)) {
    open.value = false;
  }
}

function handleKeydown(event: KeyboardEvent) {
  if (event.key === "Escape" && open.value) {
    open.value = false;
  }
}

onMounted(() => {
  document.addEventListener("mousedown", handleClickOutside);
  document.addEventListener("keydown", handleKeydown);
});

onBeforeUnmount(() => {
  document.removeEventListener("mousedown", handleClickOutside);
  document.removeEventListener("keydown", handleKeydown);
});
</script>

<template>
  <div ref="menuRef" class="relative">
    <button
      type="button"
      :aria-expanded="open"
      aria-haspopup="menu"
      :aria-label="t('shell.density')"
      class="flex h-8 items-center gap-1.5 rounded-md border border-border bg-card px-2 text-[12px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring cursor-pointer"
      :class="
        open
          ? 'border-primary/50 text-foreground'
          : 'text-muted-foreground hover:text-foreground'
      "
      @click="toggleMenu"
    >
      <SlidersHorizontal class="size-3.5" aria-hidden="true" />
      <span class="hidden sm:inline">{{ activeDensityLabel }}</span>
      <ChevronDown class="size-3 opacity-60" aria-hidden="true" />
    </button>

    <div
      v-if="open"
      role="menu"
      class="absolute right-0 top-9 z-50 w-64 overflow-hidden rounded-lg border border-border bg-popover text-popover-foreground shadow-lg shadow-black/10 animate-in fade-in zoom-in-95 duration-100"
    >
      <!-- Header -->
      <div class="border-b border-border px-3 py-2">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          {{ t("shell.display") }}
        </p>
      </div>

      <!-- Row density -->
      <div class="p-2">
        <p class="mb-1.5 flex items-center gap-1.5 px-1 text-[11px] font-medium text-muted-foreground">
          <Rows3 class="size-3" aria-hidden="true" />
          {{ t("shell.row_density") }}
        </p>
        <div class="flex flex-col gap-0.5">
          <button
            v-for="d in densityOptions"
            :key="d.key"
            type="button"
            role="menuitemradio"
            :aria-checked="uiStore.density === d.key"
            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors cursor-pointer"
            :class="
              uiStore.density === d.key
                ? 'bg-accent text-accent-foreground font-medium'
                : 'hover:bg-secondary text-foreground'
            "
            @click="selectDensity(d.key)"
          >
            <span class="min-w-0 flex-1">
              <span class="block text-[12.5px] font-medium leading-tight">{{ t(d.labelKey) }}</span>
              <span class="block text-[11px] text-muted-foreground">{{ t(d.hintKey) }}</span>
            </span>
            <Check
              v-if="uiStore.density === d.key"
              class="size-3.5 shrink-0 text-primary"
              aria-hidden="true"
            />
          </button>
        </div>
      </div>

      <!-- Appearance -->
      <div class="border-t border-border p-2">
        <p class="mb-1.5 px-1 text-[11px] font-medium text-muted-foreground">
          {{ t("shell.appearance") }}
        </p>
        <div class="flex gap-1 rounded-md bg-secondary p-0.5">
          <button
            v-for="th in themeOptions"
            :key="th.key"
            type="button"
            role="menuitemradio"
            :aria-checked="uiStore.theme === th.key"
            class="flex h-7 flex-1 items-center justify-center gap-1 rounded text-[11.5px] font-medium transition-colors cursor-pointer"
            :class="
              uiStore.theme === th.key
                ? 'bg-card text-foreground shadow-sm'
                : 'text-muted-foreground hover:text-foreground'
            "
            @click="selectTheme(th.key)"
          >
            <component :is="th.icon" class="size-3.5" aria-hidden="true" />
            {{ t(th.labelKey) }}
          </button>
        </div>
      </div>

      <!-- Reset -->
      <div class="border-t border-border p-2">
        <button
          type="button"
          role="menuitem"
          class="w-full rounded-md px-2 py-1.5 text-left text-[12px] text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground cursor-pointer"
          @click="resetPanelWidths"
        >
          {{ t("shell.reset_panels") }}
        </button>
      </div>
    </div>
  </div>
</template>
