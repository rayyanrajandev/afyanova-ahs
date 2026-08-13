/** * Afyanova Workspace Shell (Volume 1.1) *
====================================== * The host application frame that every
workspace lives inside. * Provides: top bar, nav rail, patient banner, content
region, status bar. * Workspaces are guests — they fill the content region,
never define chrome. * * Token-driven: uses bg-background, text-foreground,
border-border, etc. * from the token pipeline (tokens.ts → tokens.css →
tailwind.css). */

<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import {
  Bell,
  Boxes,
  Building2,
  CircleCheck,
  FlaskConical,
  Globe,
  HeartPulse,
  LogOut,
  Menu,
  Pill,
  ScanLine,
  Search,
  Settings,
  Stethoscope,
  TriangleAlert,
  UserPlus,
  Wallet,
} from "lucide-vue-next";
import { computed, onBeforeUnmount, onMounted, ref, type Component } from "vue";
import { I18nT, useI18n } from "vue-i18n";
import CommandPalette from "@/components/common/CommandPalette.vue";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { useCommandPalette } from "@/composables/useCommandPalette";
import { setLocale } from "@/i18n";
import { usePatientStore } from "@/stores/patientStore";
import { useRecentStore } from "@/stores/recentStore";
import { useUiStore, type DensityName, type ThemeName } from "@/stores/uiStore";

const { t, locale } = useI18n();
const uiStore = useUiStore();

// Language switcher (Volume 0.4 §3) — `setLocale` (i18n/index.ts) already
// existed and correctly drives vue-i18n's reactive locale + localStorage +
// <html lang>, but nothing ever called it: no switcher UI existed anywhere
// in the app (Volume 3.7 i18n audit, 2026-08-10). This is that UI.
function switchLocale(next: "en" | "sw") {
  setLocale(next);
}
const commandPalette = useCommandPalette();
const recentStore = useRecentStore();
const patientStore = usePatientStore();

const openCommandPalette = () => commandPalette.open();

// ---- Theme & density (Volume 1.1 §8.2 "preferences (theme/density/locale)")
// ----
// uiStore.setTheme/setDensity already existed, fully wired to the CSS
// token system (data-theme/data-density on <html>) — nothing in the app
// ever called them (2026-08-10 shell audit). Kept as visible top-bar
// controls rather than tucked into a menu, matching the language
// switcher's existing precedent (added earlier this session, deliberately
// visible rather than hidden).
const themeOptions: { value: ThemeName; labelKey: string }[] = [
  { value: "light", labelKey: "shell.theme_light" },
  { value: "dark", labelKey: "shell.theme_dark" },
  { value: "high-contrast", labelKey: "shell.theme_high_contrast" },
  { value: "deuteranopia", labelKey: "shell.theme_deuteranopia" },
  { value: "tritanopia", labelKey: "shell.theme_tritanopia" },
  { value: "imaging", labelKey: "shell.theme_imaging" },
];
const densityOptions: { value: DensityName; labelKey: string }[] = [
  { value: "compact", labelKey: "shell.density_compact" },
  { value: "comfortable", labelKey: "shell.density_comfortable" },
  { value: "spacious", labelKey: "shell.density_spacious" },
];
function onThemeChange(value: string) {
  uiStore.setTheme(value as ThemeName);
}
function onDensityChange(value: string) {
  uiStore.setDensity(value as DensityName);
}

// ---- Branding (Volume 1.1 §8.2 "Facility name") ----
// Bug fix (2026-08-10, shell audit): the top bar hardcoded the i18n string
// "shell.facility" ("Afyanova AHS") instead of reading the real,
// already-built branding system (SystemBrandingManager, shared via Inertia
// as `page.props.branding` — supports a configurable display name/logo).
// A facility that configured a custom name/logo would never have seen it
// reflected here. `t("shell.facility")` is kept only as the fallback for
// the (should-be-rare) case branding hasn't loaded.
interface PublicBranding {
  displayName: string | null;
  logoUrl: string | null;
}
const branding = computed(
  () => page.props.branding as PublicBranding | undefined,
);
const brandLabel = computed(
  () => branding.value?.displayName || t("shell.facility"),
);

// ---- Tenant/facility scope indicator (Volume 1.1 §8.2 "tenant indicator")
// ----
// Real data (`page.props.platform.scope`, ResolvePlatformScopeContext) —
// distinct from branding above: branding is "what is this product called",
// this is "which tenant/facility is the current session scoped to".
interface PlatformScopeEntity {
  name?: string | null;
  code?: string | null;
}
const platformScope = computed(
  () =>
    (
      page.props.platform as
        | {
            scope?: {
              tenant?: PlatformScopeEntity | null;
              facility?: PlatformScopeEntity | null;
            };
          }
        | undefined
    )?.scope,
);
const scopeLabel = computed(() => {
  const facility = platformScope.value?.facility;
  const tenant = platformScope.value?.tenant;
  const name = facility?.name ?? tenant?.name ?? null;
  return name;
});

// ---- Notifications (Volume 1.1 §8.2 "shows a count badge") ----
// Bug fix (2026-08-10, shell audit): the bell rendered as a static icon —
// no count was ever fetched despite a real, working endpoint
// (GET /api/v1/notifications/unread-count) already existing. A full
// notification drawer (spec: "clicking opens a notification drawer") is
// out of scope for this pass — no drawer/list UI exists yet anywhere in
// the app to open; wiring a real count is the honest slice of this to ship
// now rather than build a drawer with no reviewed content design.
const unreadNotificationCount = ref(0);
async function fetchUnreadNotificationCount() {
  try {
    const res = await fetch("/api/v1/notifications/unread-count", {
      headers: { "X-Requested-With": "XMLHttpRequest" },
    });
    if (!res.ok) return;
    const body = (await res.json()) as { data?: { count?: number } };
    unreadNotificationCount.value = body.data?.count ?? 0;
  } catch {
    // Leave at 0 — a failed count fetch shouldn't show a stale/wrong badge.
  }
}

// ---- Profile menu / sign out (Volume 1.1 §8.2) ----
// Bug fix (2026-08-10, shell audit): the avatar+name was static markup,
// not a menu — none of the spec's "profile, preferences, sign out" was
// reachable. "Audit trail link" (also named in §8.2) is deliberately not
// included: no per-user "my activity" page/route exists anywhere in the
// app to link to (only admin-scoped audit endpoints under
// /platform/admin/...), so a link here would go nowhere real.
function signOut() {
  router.post("/logout");
}

function patientInitials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}

/** Open a pinned patient quick-access item (Volume 1.3 §9.2). */
function openPinnedPatient(id: string) {
  const entry = recentStore.pinnedItems.find((i) => i.id === id);
  if (!entry) return;
  const cached = patientStore.patients.get(id);
  const apply = () => {
    patientStore.setCurrentPatient(id);
    recentStore.addRecentEntry({
      id: entry.id,
      name: entry.name,
      mrn: entry.mrn,
    });
    if (!currentPath.value.startsWith("/reception")) {
      void router.visit("/reception", { preserveState: true });
    }
  };
  if (cached) {
    apply();
  } else {
    void patientStore.fetchPatient(id).then((p) => {
      if (p || patientStore.patients.get(id)) apply();
    });
  }
}

// ---- Types ----
interface NavItem {
  labelKey: string;
  icon: Component;
  href: string;
  active?: boolean;
}

interface PatientContext {
  name: string;
  mrn: string;
  age: number;
  sex: string;
  allergies: string[];
}

// ---- Page props (Inertia) ----
const page = usePage();
const auth = computed(
  () =>
    page.props.auth as
      | { user: { name: string; email: string } | null }
      | undefined,
);
const patientContext = computed(
  () => page.props.patient as PatientContext | undefined,
);
const isOffline = ref(false);
const pendingActions = ref(0);

// ---- Nav rail items (Volume 1.1 §8.1 / Volume 0.5 §8) ----
// Labels are i18n keys, not display strings — the locale files already
// define nav.reception..nav.admin (Volume 0.4 §3.3: no hardcoded strings).
// Icons are lucide-vue-next components per the canonical nav map (Vol 0.5 §8).
const navItems: NavItem[] = [
  { labelKey: "nav.reception", icon: UserPlus, href: "/reception" },
  { labelKey: "nav.clinician", icon: Stethoscope, href: "/clinician" },
  { labelKey: "nav.nursing", icon: HeartPulse, href: "/nursing" },
  { labelKey: "nav.laboratory", icon: FlaskConical, href: "/laboratory" },
  { labelKey: "nav.radiology", icon: ScanLine, href: "/radiology" },
  { labelKey: "nav.pharmacy", icon: Pill, href: "/pharmacy" },
  { labelKey: "nav.cashier", icon: Wallet, href: "/cashier" },
  { labelKey: "nav.inventory", icon: Boxes, href: "/inventory" },
  { labelKey: "nav.admin", icon: Settings, href: "/admin" },
];

// Active workspace from URL
const currentPath = computed(() => page.url);
const activeNav = computed(
  () =>
    navItems.find((item) => currentPath.value.startsWith(item.href)) ?? null,
);

// ---- Nav rail collapse (persisted, Volume 1.1 §8.1) ----
// Bug fix (2026-08-10, shell audit): this was a *second*, independent
// `navCollapsed` ref/localStorage-key pair, duplicating state that already
// existed on uiStore (built for exactly this, but nothing used it — only
// useCommandPalette.ts imported the store, for an unrelated field). Two
// copies of the same boolean is a real bug waiting to happen (they'd only
// coincidentally agree, e.g. after both being read from the same
// localStorage key on initial load) — consolidated onto the one store.
const navCollapsed = computed(() => uiStore.navCollapsed);
function toggleNav() {
  uiStore.toggleNav();
}

// ---- Alt+1…9 workspace switching (Volume 1.1 §8.1, §12) ----
// Documented but never implemented — no keydown handler for this existed
// anywhere in the app (2026-08-10 shell audit).
function onWorkspaceShortcut(event: KeyboardEvent) {
  if (!event.altKey || event.ctrlKey || event.metaKey) return;
  const index = Number(event.key) - 1;
  if (!Number.isInteger(index) || index < 0 || index >= navItems.length) return;
  event.preventDefault();
  router.visit(navItems[index].href);
}

onMounted(() => {
  window.addEventListener("keydown", onWorkspaceShortcut);
  void fetchUnreadNotificationCount();
});

onBeforeUnmount(() => {
  window.removeEventListener("keydown", onWorkspaceShortcut);
});

// ---- Online/offline detection (Volume 1.4 §7, P8) ----
if (typeof window !== "undefined") {
  isOffline.value = !navigator.onLine;
  window.addEventListener("online", () => {
    isOffline.value = false;
  });
  window.addEventListener("offline", () => {
    isOffline.value = true;
  });
}
</script>

<template>
  <TooltipProvider>
    <div
      class="flex h-screen flex-col overflow-hidden bg-background text-foreground"
    >
      <!-- TOP BAR (Volume 1.1 §8.2) — Left / Center / Right. The center trigger
         is genuinely centered by giving the left and right zones equal
         `flex-1` growth (was `justify-between` with 3 flex children, which
         only centers the middle one when the other two happen to end up
         equal width — they didn't). -->
      <header
        class="flex items-center gap-3 border-b border-border bg-surface px-4"
        :style="{ height: 'var(--shell-topbar-height)' }"
      >
        <!-- Left: nav toggle, brand, tenant/facility scope indicator -->
        <div class="flex min-w-0 flex-1 items-center gap-3">
          <button
            class="focus-ring shrink-0 rounded-md p-1.5 text-muted-foreground hover:bg-muted"
            :aria-label="navCollapsed ? t('nav.expand') : t('nav.collapse')"
            @click="toggleNav"
          >
            <Menu class="size-5" aria-hidden="true" />
          </button>
          <img
            v-if="branding?.logoUrl"
            :src="branding.logoUrl"
            alt=""
            class="size-5 shrink-0 rounded-sm"
          />
          <span class="truncate text-sm font-semibold text-foreground">{{
            brandLabel
          }}</span>
          <!-- Tenant indicator (§8.2) — which tenant/facility this session is
             scoped to, distinct from the brand name above. Hidden when no
             scope is resolved (e.g. platform-level views). -->
          <span
            v-if="scopeLabel"
            class="hidden shrink-0 items-center gap-1 truncate rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground md:inline-flex"
            :title="scopeLabel"
          >
            <Building2 class="size-3" aria-hidden="true" />
            {{ scopeLabel }}
          </span>
        </div>

        <!-- Center: command palette trigger -->
        <button
          class="focus-ring hidden items-center gap-2 rounded-md border border-border bg-muted px-3 py-1.5 text-sm text-muted-foreground hover:bg-accent sm:flex"
          :aria-label="t('shell.command_palette')"
          @click="openCommandPalette"
        >
          <Search class="size-4" aria-hidden="true" />
          <span>{{ t("shell.search") }}</span>
          <kbd
            class="ml-2 rounded border border-border bg-surface px-1.5 text-xs"
            >⌘K</kbd
          >
        </button>

        <!-- Right: language, theme, density, notifications, profile -->
        <div class="flex flex-1 items-center justify-end gap-2">
          <!-- Language switcher (Volume 0.4 §3). Rebuilt 2026-08-10: was a
             fixed 2-button EN/SW toggle — didn't scale past two languages,
             showed ISO codes instead of each language's own native name
             (a Swahili-only speaker can't be assumed to recognize "SW"),
             and (found in the same pass) had silently gained a
             `hidden ... lg:flex` class that hid it below the lg breakpoint
             with zero fallback. A Select dropdown fixes all three: it's
             one compact trigger regardless of language count, options are
             literal native names never run through translation (see the
             i18n keys' comment), and it doesn't need a breakpoint-hide at
             all. Also now consistent with the Theme/Density controls next
             to it instead of a one-off pattern. -->
          <Select
            :model-value="locale"
            @update:model-value="(v) => switchLocale(String(v) as 'en' | 'sw')"
          >
            <SelectTrigger
              class="h-8 w-auto gap-1.5 border-border px-2 text-xs"
              :aria-label="t('shell.language')"
            >
              <Globe class="size-3.5" aria-hidden="true" />
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="en">{{
                t("shell.language_name_en")
              }}</SelectItem>
              <SelectItem value="sw">{{
                t("shell.language_name_sw")
              }}</SelectItem>
            </SelectContent>
          </Select>

          <!-- Density toggle (§8.2 "preferences (theme/density/locale)") -->
          <Select
            :model-value="uiStore.density"
            @update:model-value="(v) => onDensityChange(String(v))"
          >
            <SelectTrigger
              class="hidden h-8 w-auto gap-1.5 border-border px-2 text-xs lg:flex"
              :aria-label="t('shell.density')"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="opt in densityOptions"
                :key="opt.value"
                :value="opt.value"
              >
                {{ t(opt.labelKey) }}
              </SelectItem>
            </SelectContent>
          </Select>

          <!-- Theme toggle (§8.2) -->
          <Select
            :model-value="uiStore.theme"
            @update:model-value="(v) => onThemeChange(String(v))"
          >
            <SelectTrigger
              class="hidden h-8 w-auto gap-1.5 border-border px-2 text-xs lg:flex"
              :aria-label="t('shell.theme')"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="opt in themeOptions"
                :key="opt.value"
                :value="opt.value"
              >
                {{ t(opt.labelKey) }}
              </SelectItem>
            </SelectContent>
          </Select>

          <!-- Notifications (§8.2 "shows a count badge") -->
          <button
            class="focus-ring relative rounded-md p-1.5 text-muted-foreground hover:bg-muted"
            :aria-label="
              unreadNotificationCount > 0
                ? t('shell.notifications_unread', {
                    count: unreadNotificationCount,
                  })
                : t('shell.notifications')
            "
          >
            <Bell class="size-5" aria-hidden="true" />
            <span
              v-if="unreadNotificationCount > 0"
              class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-critical px-1 text-xs leading-none font-semibold text-critical-foreground"
            >
              {{
                unreadNotificationCount > 99 ? "99+" : unreadNotificationCount
              }}
            </span>
          </button>

          <!-- Profile menu (§8.2: profile, preferences, sign out) -->
          <Popover v-if="auth?.user">
            <PopoverTrigger as-child>
              <button
                class="focus-ring flex items-center gap-2 rounded-md p-1 hover:bg-muted"
                :aria-label="t('shell.profile_menu')"
              >
                <Avatar class="size-8">
                  <AvatarFallback
                    class="bg-primary text-xs font-medium text-primary-foreground"
                  >
                    {{ auth.user.name.charAt(0).toUpperCase() }}
                  </AvatarFallback>
                </Avatar>
                <span class="hidden text-sm text-foreground sm:inline">{{
                  auth.user.name
                }}</span>
              </button>
            </PopoverTrigger>
            <PopoverContent class="w-56" align="end">
              <div class="border-b border-border pb-2">
                <p class="truncate text-sm font-medium text-foreground">
                  {{ auth.user.name }}
                </p>
                <p class="truncate text-xs text-muted-foreground">
                  {{ auth.user.email }}
                </p>
              </div>
              <button
                type="button"
                class="focus-ring mt-2 flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm text-critical hover:bg-critical/10"
                @click="signOut"
              >
                <LogOut class="size-4" aria-hidden="true" />
                {{ t("shell.sign_out") }}
              </button>
            </PopoverContent>
          </Popover>
        </div>
      </header>

      <!-- BODY: nav rail + content -->
      <div class="flex flex-1 overflow-hidden">
        <!-- NAV RAIL (Volume 1.1 §8.1) -->
        <nav
          class="flex flex-col border-r border-sidebar-border bg-sidebar transition-all duration-200"
          :style="{
            width: navCollapsed
              ? 'var(--shell-navrail-width)'
              : 'var(--shell-navrail-width-expanded)',
          }"
          :aria-label="t('nav.landmark_label')"
        >
          <!-- role=tablist/tab (§8.1, §12) added 2026-08-10 — documented but
             never implemented; only `aria-current` existed before. Kept
             alongside aria-current (still correct for real page-navigating
             links) rather than instead of it. -->
          <ul class="flex flex-1 flex-col gap-1 p-2" role="tablist">
            <li v-for="item in navItems" :key="item.href" role="presentation">
              <!-- Tooltip only enabled while collapsed (Volume 1.2's
                 component table, added 2026-08-10) — the label is already
                 visible as text when expanded, so a tooltip there would
                 just be a redundant echo. Replaces the native `title`
                 attribute this used to fall back to — but a tooltip's
                 `aria-describedby` only *supplements* the accessible name,
                 it doesn't provide one, so `aria-label` below (same
                 collapsed-only condition `title` used to have) is what
                 actually keeps this link nameable to assistive tech and
                 findable by accessible-name queries once the visible
                 label span stops rendering; caught by a Playwright a11y
                 query failing on first pass, not by inspection. -->
              <Tooltip :disabled="!navCollapsed">
                <TooltipTrigger as-child>
                  <a
                    :href="item.href"
                    role="tab"
                    class="focus-ring flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors"
                    :class="
                      item.href === activeNav?.href
                        ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                        : 'text-sidebar-foreground hover:bg-sidebar-accent/50'
                    "
                    :aria-current="
                      item.href === activeNav?.href ? 'page' : undefined
                    "
                    :aria-selected="item.href === activeNav?.href"
                    :aria-label="navCollapsed ? t(item.labelKey) : undefined"
                  >
                    <span
                      class="flex h-5 w-5 shrink-0 items-center justify-center"
                    >
                      <component
                        :is="item.icon"
                        class="size-5 transition-colors"
                        :class="
                          item.href === activeNav?.href
                            ? 'text-sidebar-accent-foreground'
                            : 'text-sidebar-foreground'
                        "
                        aria-hidden="true"
                      />
                    </span>
                    <span v-if="!navCollapsed">{{ t(item.labelKey) }}</span>
                  </a>
                </TooltipTrigger>
                <TooltipContent side="right">{{
                  t(item.labelKey)
                }}</TooltipContent>
              </Tooltip>
            </li>
          </ul>

          <!-- Pinned patients quick access (Volume 1.3 §9.2) -->
          <div
            v-if="recentStore.pinnedItems.length > 0"
            class="border-t border-sidebar-border px-2 py-2"
          >
            <p
              v-if="!navCollapsed"
              class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-sidebar-foreground/60"
            >
              {{ t("recent.pinned_section") }}
            </p>
            <ul class="space-y-1">
              <!-- Tooltip, not the native `title` this used to carry
                   (workspace tooltip audit, 2026-08-11). Deliberately
                   NOT `:disabled="!navCollapsed"` like the nav items
                   above: those are short, curated i18n labels that never
                   truncate, so a tooltip while expanded would be pure
                   echo. Patient names are variable-length real data —
                   `truncate` on the visible span means an expanded-but-
                   long name can genuinely need the same hover fallback
                   `title` used to give unconditionally; disabling here
                   would have quietly dropped that. Always-on costs a
                   redundant hover on short names that already fit, which
                   is the lesser problem. `aria-label` added for the
                   collapsed case specifically — the visible name span
                   disappears then, so this is what keeps the button
                   nameable to assistive tech (mirrors the nav items'
                   own fix, same reasoning, just not the same disabled
                   condition on the Tooltip itself). -->
              <li v-for="item in recentStore.pinnedItems" :key="item.id">
                <Tooltip>
                  <TooltipTrigger as-child>
                    <button
                      type="button"
                      class="focus-ring flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors text-sidebar-foreground hover:bg-sidebar-accent/50"
                      :aria-label="navCollapsed ? item.name : undefined"
                      @click="openPinnedPatient(item.id)"
                    >
                      <!-- `text-[10px]`, not `text-xs` (found live, 2026-08-11):
                           this circle is the same `size-6` (24px) as the
                           Recent Patients list and the results table in the
                           Patients tab, but its initials were rendering 2px
                           larger than both — measured, not eyeballed. Matches
                           the same arbitrary-value pattern already used in
                           PatientSearchPanel.vue for the other two (no
                           smaller token exists in this design system's type
                           scale below `text-xs`/12px). -->
                      <Avatar class="size-6 shrink-0">
                        <AvatarFallback class="text-[10px]">
                          {{ patientInitials(item.name) }}
                        </AvatarFallback>
                      </Avatar>
                      <span v-if="!navCollapsed" class="truncate text-left">{{
                        item.name
                      }}</span>
                    </button>
                  </TooltipTrigger>
                  <TooltipContent side="right">{{ item.name }}</TooltipContent>
                </Tooltip>
              </li>
            </ul>
          </div>
        </nav>

        <!-- CONTENT REGION -->
        <div class="flex flex-1 flex-col overflow-hidden">
          <!-- Patient banner (Volume 1.1 §7) -->
          <section
            v-if="patientContext"
            class="flex items-center gap-4 border-b border-border bg-surface px-4"
            style="height: var(--shell-banner-height)"
            :aria-label="t('shell.patient_context')"
          >
            <div class="flex items-center gap-2">
              <span class="text-sm font-semibold text-foreground">{{
                patientContext.name
              }}</span>
              <span class="text-xs text-muted-foreground"
                >{{ t("patient.mrn") }}: {{ patientContext.mrn }}</span
              >
              <span class="text-xs text-muted-foreground"
                >{{ patientContext.age }}y {{ patientContext.sex }}</span
              >
            </div>
            <div
              v-if="patientContext.allergies.length > 0"
              class="flex items-center gap-1.5"
            >
              <Badge variant="critical" class="inline-flex items-center gap-1">
                <TriangleAlert class="h-3 w-3" aria-hidden="true" />
                {{
                  t("patient.allergies_count", {
                    count: patientContext.allergies.length,
                  })
                }}
              </Badge>
            </div>
            <div v-else>
              <Badge variant="success" class="inline-flex items-center gap-1">
                <CircleCheck class="h-3 w-3" aria-hidden="true" />
                {{ t("patient.no_allergies") }}
              </Badge>
            </div>
          </section>

          <!-- Main content — workspace fills this -->
          <main class="flex-1 overflow-auto bg-background p-4" tabindex="-1">
            <slot />
          </main>

          <!-- Status bar (Volume 1.1 §2) -->
          <footer
            class="flex items-center justify-between border-t border-border bg-surface px-4 text-xs text-muted-foreground"
            style="height: var(--shell-statusbar-height)"
          >
            <div class="flex items-center gap-4">
              <span
                v-if="isOffline"
                class="flex items-center gap-1.5 font-medium text-warning"
                role="status"
              >
                <span class="h-2 w-2 rounded-full bg-warning"></span>
                {{ t("shell.offline") }}
                <span v-if="pendingActions > 0"
                  >· {{ t("shell.queued", { count: pendingActions }) }}</span
                >
              </span>
              <span v-else class="flex items-center gap-1.5">
                <span class="h-2 w-2 rounded-full bg-success"></span>
                {{ t("shell.online") }}
              </span>
            </div>
            <div class="hidden sm:block">
              <!-- i18n-t (Volume 0.4 §3.3) keeps the styled <kbd> inside the
                             translated sentence with correct word order per locale,
                             instead of splitting "Press"/"to search" as two hardcoded
                             fragments around a fixed-position glyph. -->
              <I18nT keypath="shell.search_hint" tag="span">
                <template #key>
                  <kbd class="rounded border border-border bg-muted px-1"
                    >⌘K</kbd
                  >
                </template>
              </I18nT>
            </div>
          </footer>
        </div>
      </div>

      <!-- Command palette (Volume 1.1 §4.2, §6) — shell-owned, teleported to body -->
      <CommandPalette />
    </div>
  </TooltipProvider>
</template>
