/**
 * Afyanova Workspace Shell (Volume 1.1)
 * ======================================
 * The host application frame that every workspace lives inside.
 * Provides: full-height nav rail, top bar, patient banner, content region, status bar.
 * Workspaces are guests — they fill the content region, never define chrome.
 *
 * Full-height Sidebar Architecture (v0.app aesthetic):
 * - Left: Full-height Nav Rail from top to bottom with brand header, module links, and shift widget.
 * - Right: TopBar (h-12), Patient Banner (if present), Workspace main content, and Footer Status Bar (h-7).
 */

<script setup lang="ts">
import { Link, router, usePage } from "@inertiajs/vue3";
import {
  Activity,
  Bell,
  Boxes,
  Building2,
  ChevronDown,
  CircleCheck,
  FlaskConical,
  Globe,
  HeartPulse,
  LogOut,
  PanelsTopLeft,
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
import { useI18n } from "vue-i18n";
import CommandPalette from "@/components/common/CommandPalette.vue";
import DisplayMenu from "@/components/shell/DisplayMenu.vue";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { useCommandPalette } from "@/composables/useCommandPalette";
import { setLocale } from "@/i18n";
import { usePatientStore } from "@/stores/patientStore";
import { useQueueStore } from "@/stores/queueStore";
import { useRecentStore } from "@/stores/recentStore";
import { useUiStore } from "@/stores/uiStore";

const { t, locale } = useI18n({ useScope: "global" });
const uiStore = useUiStore();

// Language switcher
function switchLocale(next: "en" | "sw") {
  locale.value = next;
  setLocale(next);
  try {
    uiStore.setLocale(next);
  } catch {}
}

const commandPalette = useCommandPalette();
const recentStore = useRecentStore();
const patientStore = usePatientStore();
const queueStore = useQueueStore();

const openCommandPalette = () => commandPalette.open();

// Dynamic shift progress calculation (08:00 to 18:00 window)
const shiftProgressPercent = computed(() => {
  const now = new Date();
  const hours = now.getHours();
  const minutes = now.getMinutes();
  const currentTotalMinutes = hours * 60 + minutes;
  const startMinutes = 8 * 60;
  const endMinutes = 18 * 60;
  if (currentTotalMinutes <= startMinutes) return 5;
  if (currentTotalMinutes >= endMinutes) return 100;
  return Math.min(
    100,
    Math.max(
      5,
      Math.round(
        ((currentTotalMinutes - startMinutes) / (endMinutes - startMinutes)) *
          100,
      ),
    ),
  );
});

// ---- Real-time metrics for Status Bar / Footer ----
const slaBreaches = computed(() => {
  if (!queueStore.tasks || queueStore.tasks.length === 0) return 0;
  return queueStore.tasks.filter(
    (t) =>
      (typeof t.waitMinutes === "number" && t.waitMinutes >= 60) ||
      t.priority === "critical",
  ).length;
});

const avgWait = computed(() => {
  if (!queueStore.tasks || queueStore.tasks.length === 0) return "0m";
  const tasksWithWait = queueStore.tasks.filter(
    (t) => typeof t.waitMinutes === "number" && t.waitMinutes > 0,
  );
  if (tasksWithWait.length === 0) return "0m";
  const total = tasksWithWait.reduce((acc, t) => acc + (t.waitMinutes || 0), 0);
  const avg = Math.round(total / tasksWithWait.length);
  return `${avg}m`;
});

const totalPatientsCount = computed(() => {
  if (patientStore.totalPatientCount && patientStore.totalPatientCount > 0) {
    return patientStore.totalPatientCount;
  }
  return patientStore.patients?.size ?? 0;
});

// ---- Branding ----
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

// ---- Tenant/facility scope indicator ----
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

// ---- Notifications ----
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
    unreadNotificationCount.value = 0;
  }
}

// ---- Profile menu / sign out ----
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

/** Open a pinned patient quick-access item */
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
      if (p || patientStore.patients.get(id)) {
        apply();
        return;
      }
      // Pinned items are localStorage-persisted, so one can outlive the patient
      // it points at. Clicking it previously did nothing at all; drop it
      // instead, so the quick-access list stops offering a record that is gone.
      recentStore.unpin(id);
      recentStore.removeRecent(id);
    });
  }
}

// ---- Types ----
interface NavItem {
  labelKey: string;
  icon: Component;
  href: string;
  badge?: number;
  active?: boolean;
  permission?: string;
  permissions?: string[];
  roles?: string[];
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
      | {
          user: { name: string; email: string } | null;
          permissions?: string[];
          roleCodes?: string[];
          isFacilitySuperAdmin?: boolean;
          isPlatformSuperAdmin?: boolean;
        }
      | undefined,
);

const userDisplayName = computed(() => {
  return auth.value?.user?.name || "Staff Member";
});

const userInitials = computed(() => {
  return patientInitials(userDisplayName.value) || "SM";
});

// Density-driven workspace padding
const contentPaddingClass = computed(() => {
  switch (uiStore.density) {
    case "compact":
      return "p-2";
    case "spacious":
      return "p-4";
    case "comfortable":
    default:
      return "p-2.5 sm:p-3";
  }
});

const patientContext = computed(
  () => page.props.patient as PatientContext | undefined,
);
const isOffline = ref(false);
const pendingActions = ref(0);

// Live clock for shift widget
const currentTime = ref("");
function updateClock() {
  const now = new Date();
  currentTime.value = now.toLocaleTimeString([], {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}
let clockTimer: ReturnType<typeof setInterval> | null = null;

// ---- Nav rail items (dynamic badge from queue store) ----
const navItems = computed<NavItem[]>(() => [
  {
    labelKey: "nav.reception",
    icon: UserPlus,
    href: "/reception",
    permissions: ["appointment.check-in", "reception.access"],
    roles: ["receptionist", "ADMIN.REGISTRATION", "registration_clerk"],
  },
  {
    labelKey: "nav.clinician",
    icon: Stethoscope,
    href: "/clinician",
    permissions: ["medication.prescribe", "clinician.access"],
    roles: [
      "clinical_officer",
      "CLINICAL.OFFICER",
      "medical-officer",
      "CLINICAL.PHYSICIAN",
      "doctor",
      "physician",
      "surgeon",
      "CLINICAL.SURGEON",
    ],
  },
  {
    labelKey: "nav.nursing",
    icon: HeartPulse,
    href: "/nursing",
    permissions: ["inpatient.ward.create-task", "inpatient.ward.read", "nursing.access"],
    roles: [
      "nurse",
      "nurse-officer",
      "CLINICAL.NURSE",
      "nurse-midwife",
      "CLINICAL.NURSE.MIDWIFE",
      "medical_attendant",
    ],
  },
  {
    labelKey: "nav.laboratory",
    icon: FlaskConical,
    href: "/laboratory",
    permissions: ["lab.result.enter", "lab.test.perform", "laboratory.access"],
    roles: [
      "lab_technician",
      "lab-technologist",
      "LAB.STAFF",
      "lab-supervisor",
      "LAB.SUPERVISOR",
      "lab-manager",
      "LAB.MANAGER",
    ],
  },
  {
    labelKey: "nav.radiology",
    icon: ScanLine,
    href: "/radiology",
    permissions: ["imaging.perform", "imaging.result.enter", "radiology.access"],
    roles: [
      "radiographer",
      "RADIOLOGY.STAFF",
      "radiographer-senior",
      "RADIOLOGY.SUPERVISOR",
      "radiologist",
    ],
  },
  {
    labelKey: "nav.pharmacy",
    icon: Pill,
    href: "/pharmacy",
    permissions: ["medication.dispense", "pharmacy.access"],
    roles: [
      "dispenser",
      "PHARMACY.STAFF",
      "pharmacist",
      "PHARMACY.SUPERVISOR",
    ],
  },
  {
    labelKey: "nav.cashier",
    icon: Wallet,
    href: "/cashier",
    permissions: ["billing.payments.record", "cashier.access"],
    roles: ["cashier", "FINANCE.CASHIER", "accountant"],
  },
  {
    labelKey: "nav.inventory",
    icon: Boxes,
    href: "/inventory",
    permissions: ["inventory.manage", "inventory.access"],
    roles: ["inventory_clerk", "storekeeper"],
  },
  {
    labelKey: "nav.admin",
    icon: Settings,
    href: "/admin",
    permissions: ["staff.create", "departments.create", "admin.access", "platform.admin"],
    roles: ["super_admin", "admin", "hospital-admin", "ADMIN.FACILITY"],
  },
]);

// Visible nav items filtered by permission and role
const visibleNavItems = computed<NavItem[]>(() => {
  const permissions = (auth.value?.permissions ?? []).map((p) => p.toLowerCase());
  const roleCodes = (auth.value?.roleCodes ?? []).map((r) => r.toLowerCase());
  const isSuperAdmin =
    auth.value?.isPlatformSuperAdmin === true ||
    auth.value?.isFacilitySuperAdmin === true ||
    roleCodes.includes("super_admin") ||
    roleCodes.includes("admin.facility");

  if (isSuperAdmin) {
    return navItems.value;
  }

  return navItems.value.filter((item) => {
    // If no permission or roles specified, item is open
    if (
      !item.permission &&
      (!item.permissions || item.permissions.length === 0) &&
      (!item.roles || item.roles.length === 0)
    ) {
      return true;
    }

    // Check single permission
    if (item.permission && permissions.includes(item.permission.toLowerCase())) {
      return true;
    }

    // Check permissions array (any match)
    if (
      item.permissions &&
      item.permissions.some((p) => permissions.includes(p.toLowerCase()))
    ) {
      return true;
    }

    // Check roleCodes array (any match)
    if (
      item.roles &&
      item.roles.some((r) => roleCodes.includes(r.toLowerCase()))
    ) {
      return true;
    }

    return false;
  });
});

// Active workspace from URL
const currentPath = computed(() => page.url);
const activeNav = computed(
  () =>
    visibleNavItems.value.find((item) =>
      currentPath.value.startsWith(item.href),
    ) ?? visibleNavItems.value[0],
);

const breadcrumbWorkspace = computed(() => {
  if (!activeNav.value) return t("nav.reception");
  return t(activeNav.value.labelKey);
});

const activePatientName = computed(() => {
  const p = patientStore.currentPatient;
  if (!p || !p.name?.[0]) return null;
  const given = p.name[0].given?.join(" ") || "";
  const family = p.name[0].family || "";
  return `${given} ${family}`.trim() || null;
});

// Nav rail collapse
const navCollapsed = computed(() => uiStore.navCollapsed);
function toggleNav() {
  uiStore.toggleNav();
}

// Alt+1…9 workspace switching
function onWorkspaceShortcut(event: KeyboardEvent) {
  if (!event.altKey || event.ctrlKey || event.metaKey) return;
  const index = Number(event.key) - 1;
  if (
    !Number.isInteger(index) ||
    index < 0 ||
    index >= visibleNavItems.value.length
  )
    return;
  event.preventDefault();
  router.visit(visibleNavItems.value[index].href);
}

function onGlobalCommandShortcut(event: KeyboardEvent) {
  const isInput =
    event.target instanceof HTMLInputElement ||
    event.target instanceof HTMLTextAreaElement ||
    (event.target as HTMLElement)?.isContentEditable;
  if (
    ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") ||
    (!isInput &&
      !event.ctrlKey &&
      !event.metaKey &&
      !event.altKey &&
      event.key === "/")
  ) {
    event.preventDefault();
    uiStore.commandPaletteOpen
      ? uiStore.closeCommandPalette()
      : uiStore.openCommandPalette();
  }
}

onMounted(() => {
  window.addEventListener("keydown", onWorkspaceShortcut);
  window.addEventListener("keydown", onGlobalCommandShortcut);
  void fetchUnreadNotificationCount();
  updateClock();
  clockTimer = setInterval(updateClock, 30000);
});

onBeforeUnmount(() => {
  window.removeEventListener("keydown", onWorkspaceShortcut);
  window.removeEventListener("keydown", onGlobalCommandShortcut);
  if (clockTimer) clearInterval(clockTimer);
});

// Online/offline detection
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
      class="flex h-screen overflow-hidden bg-background text-foreground"
    >
      <!-- ================================================================
           1. FULL-HEIGHT NAV RAIL (AppRail) — Top to Bottom
           ================================================================ -->
      <nav
        aria-label="Modules"
        class="flex shrink-0 flex-col border-r border-border bg-sidebar transition-all duration-200"
        :class="navCollapsed ? 'w-[56px]' : 'w-[212px]'"
      >
        <!-- Brand Header (Exact h-12 to align with TopBar across vertical split) -->
        <div
          v-if="!navCollapsed"
          class="flex h-12 shrink-0 items-center gap-2 border-b border-border px-3"
        >
          <div
            class="grid size-7 place-items-center rounded-md bg-primary text-primary-foreground shrink-0 shadow-sm"
          >
            <Activity class="size-4" :stroke-width="2.5" aria-hidden="true" />
          </div>
          <div class="min-w-0 flex-1">
            <p class="truncate text-[13px] font-semibold leading-tight tracking-tight text-foreground">
              {{ branding?.displayName || "Afyanova" }}
            </p>
            <p class="truncate text-[10.5px] leading-tight text-muted-foreground">
              {{ scopeLabel || "Dar Main Hospital" }}
            </p>
          </div>
        </div>
        <div
          v-else
          class="flex h-12 shrink-0 items-center justify-center border-b border-border"
        >
          <Tooltip>
            <TooltipTrigger as-child>
              <div
                class="grid size-7 place-items-center rounded-md bg-primary text-primary-foreground shadow-sm cursor-pointer"
                @click="toggleNav"
              >
                <Activity class="size-4" :stroke-width="2.5" aria-hidden="true" />
              </div>
            </TooltipTrigger>
            <TooltipContent side="right">
              {{ branding?.displayName || "Afyanova" }} — {{ scopeLabel || "Dar Main Hospital" }}
            </TooltipContent>
          </Tooltip>
        </div>

        <!-- Nav List -->
        <ul class="flex flex-1 flex-col gap-px p-2 overflow-y-auto" role="tablist">
          <li v-for="(item, idx) in visibleNavItems" :key="item.href" role="presentation">
            <Tooltip :disabled="!navCollapsed">
              <TooltipTrigger as-child>
                <Link
                  :href="item.href"
                  role="tab"
                  :aria-current="item.href === activeNav?.href ? 'page' : undefined"
                  :aria-selected="item.href === activeNav?.href"
                  :aria-label="navCollapsed ? t(item.labelKey) : undefined"
                  class="group relative flex h-8 items-center gap-2.5 rounded-md px-2 text-[13px] transition-colors"
                  :class="[
                    item.href === activeNav?.href
                      ? 'bg-sidebar-accent font-semibold text-accent-foreground shadow-xs'
                      : 'text-muted-foreground hover:bg-secondary hover:text-foreground',
                    navCollapsed ? 'justify-center px-0' : '',
                  ]"
                >
                  <!-- Active Indicator Accent Pill -->
                  <span
                    v-if="item.href === activeNav?.href"
                    class="absolute left-0 top-1.5 bottom-1.5 w-[2.5px] rounded-r-full bg-primary"
                    aria-hidden="true"
                  />

                  <component
                    :is="item.icon"
                    class="size-4 shrink-0 transition-colors"
                    :class="item.href === activeNav?.href ? 'text-primary' : 'text-muted-foreground group-hover:text-foreground'"
                    :stroke-width="item.href === activeNav?.href ? 2.3 : 1.9"
                    aria-hidden="true"
                  />
                  <span v-if="!navCollapsed" class="flex-1 truncate">
                    {{ t(item.labelKey) }}
                  </span>
                  <span
                    v-if="!navCollapsed && item.badge"
                    class="num rounded px-1 font-mono text-[10px] font-semibold transition-colors"
                    :class="
                      item.href === activeNav?.href
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-secondary text-muted-foreground'
                    "
                  >
                    {{ item.badge }}
                  </span>
                </Link>
              </TooltipTrigger>
              <TooltipContent side="right" class="flex items-center gap-2">
                <span>{{ t(item.labelKey) }}</span>
                <span v-if="item.badge" class="opacity-70">({{ item.badge }})</span>
                <kbd class="ml-auto rounded border border-border/60 bg-secondary/80 px-1 py-px font-mono text-[9.5px] text-muted-foreground">Alt+{{ idx + 1 }}</kbd>
              </TooltipContent>
            </Tooltip>
          </li>
        </ul>

        <!-- Pinned Patients quick access -->
        <div
          v-if="recentStore.pinnedItems.length > 0"
          class="border-t border-sidebar-border px-2 py-2"
        >
          <p
            v-if="!navCollapsed"
            class="px-2 pb-1 text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground"
          >
            {{ t("recent.pinned_section") }}
          </p>
          <ul class="space-y-1">
            <li v-for="item in recentStore.pinnedItems" :key="item.id">
              <Tooltip>
                <TooltipTrigger as-child>
                  <button
                    type="button"
                    class="flex w-full items-center gap-2.5 rounded-md px-2 py-1 text-xs transition-colors text-sidebar-foreground hover:bg-secondary cursor-pointer"
                    :class="navCollapsed ? 'justify-center px-0' : ''"
                    :aria-label="navCollapsed ? item.name : undefined"
                    @click="openPinnedPatient(item.id)"
                  >
                    <Avatar class="size-5 shrink-0">
                      <AvatarFallback class="text-[9px] bg-secondary text-muted-foreground font-semibold">
                        {{ patientInitials(item.name) }}
                      </AvatarFallback>
                    </Avatar>
                    <span v-if="!navCollapsed" class="truncate text-left text-[12px]">
                      {{ item.name }}
                    </span>
                  </button>
                </TooltipTrigger>
                <TooltipContent side="right">{{ item.name }}</TooltipContent>
              </Tooltip>
            </li>
          </ul>
        </div>

        <!-- Bottom Shift Card -->
        <div class="mt-auto p-2">
          <div
            v-if="!navCollapsed"
            class="rounded-md border border-border bg-card p-2.5 shadow-sm"
          >
            <div class="flex items-center justify-between">
              <span class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">
                {{ t("shell.shift_label") }}
              </span>
              <span class="num font-mono text-[11px] font-semibold text-foreground">
                {{ currentTime || "14:58" }}
              </span>
            </div>
            <p class="mt-1.5 text-[11.5px] leading-snug text-muted-foreground">
              {{ t("shell.shift_closes") }}
            </p>
            <div class="mt-2 h-1 overflow-hidden rounded-full bg-secondary">
              <div
                class="h-full rounded-full bg-primary transition-all duration-500"
                :style="{ width: `${shiftProgressPercent}%` }"
              />
            </div>
          </div>
          <div
            v-else
            class="flex justify-center"
          >
            <Tooltip>
              <TooltipTrigger as-child>
                <div class="flex size-7 items-center justify-center rounded-md border border-border bg-card text-[10px] font-mono font-semibold text-muted-foreground">
                  {{ currentTime.slice(0, 2) || "14" }}
                </div>
              </TooltipTrigger>
              <TooltipContent side="right">
                {{ t("shell.shift_closes") }}
              </TooltipContent>
            </Tooltip>
          </div>
        </div>
      </nav>

      <!-- ================================================================
           2. RIGHT COLUMN: TopBar + Workspace Content + Status Bar Footer
           ================================================================ -->
      <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
        <!-- TOP BAR (h-12 Header) -->
        <header
          class="flex h-12 shrink-0 items-center gap-3 border-b border-border bg-surface px-3"
        >
          <!-- Left: Nav Toggle & Breadcrumbs -->
          <div class="flex items-center gap-2">
            <Tooltip>
              <TooltipTrigger as-child>
                <button
                  type="button"
                  class="inline-flex size-7 items-center justify-center rounded-md border border-transparent text-muted-foreground transition-colors hover:border-border hover:bg-secondary hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring cursor-pointer"
                  :aria-label="navCollapsed ? t('nav.expand') : t('nav.collapse')"
                  @click="toggleNav"
                >
                  <PanelsTopLeft class="size-4" aria-hidden="true" />
                </button>
              </TooltipTrigger>
              <TooltipContent side="bottom">
                {{ navCollapsed ? t("nav.expand") : t("nav.collapse") }}
              </TooltipContent>
            </Tooltip>

            <div class="hidden items-center gap-1.5 text-[12.5px] md:flex">
              <span :class="activePatientName ? 'text-muted-foreground' : 'font-medium text-foreground'">
                {{ breadcrumbWorkspace }}
              </span>
              <template v-if="activePatientName">
                <span class="text-border-strong font-light">/</span>
                <span class="font-medium text-foreground truncate max-w-[200px]">
                  {{ activePatientName }}
                </span>
              </template>
            </div>
          </div>

          <!-- Center: Search bar trigger -->
          <div class="mx-auto w-full max-w-md">
            <button
              type="button"
              class="group relative flex h-8 w-full items-center rounded-md border border-border bg-card pl-8 pr-2 text-left transition-colors hover:border-primary/50 focus-visible:border-primary/60 focus-visible:ring-2 focus-visible:ring-ring/20 cursor-pointer"
              :aria-label="t('shell.command_palette')"
              @click="openCommandPalette"
            >
              <Search
                class="absolute left-2.5 size-3.5 text-muted-foreground group-hover:text-foreground transition-colors"
                aria-hidden="true"
              />
              <span class="h-full flex items-center text-[12.5px] text-muted-foreground select-none truncate">
                {{ t("shell.search_placeholder") }}
              </span>
              <kbd
                class="ml-auto rounded border border-border bg-secondary px-1 py-px font-mono text-[10px] font-medium text-muted-foreground"
              >
                ⌘K
              </kbd>
            </button>
          </div>

          <!-- Right: Language Switcher, Display Menu, Notifications & Profile -->
          <div class="flex items-center gap-1.5 sm:gap-2">
            <!-- Language Selector (Responsive: icon on mobile/tablet, full label on xl) -->
            <Popover>
              <PopoverTrigger as-child>
                <button
                  type="button"
                  class="flex h-8 items-center gap-1.5 rounded-md border border-border bg-card px-2 text-[12px] text-muted-foreground transition-colors hover:text-foreground cursor-pointer"
                  :aria-label="t('shell.language')"
                >
                  <Globe class="size-3.5" aria-hidden="true" />
                  <span class="hidden xl:inline">{{ locale === "sw" ? t("shell.language_name_sw") : t("shell.language_name_en") }}</span>
                  <span class="hidden sm:inline xl:hidden font-mono uppercase text-[10.5px] font-medium">{{ locale }}</span>
                  <ChevronDown class="size-3 opacity-60" aria-hidden="true" />
                </button>
              </PopoverTrigger>
              <PopoverContent class="w-36 p-1" align="end">
                <button
                  type="button"
                  class="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-xs text-foreground transition-colors hover:bg-secondary cursor-pointer"
                  :class="{ 'font-semibold bg-accent text-accent-foreground': locale === 'en' }"
                  @click="switchLocale('en')"
                >
                  {{ t("shell.language_name_en") }}
                </button>
                <button
                  type="button"
                  class="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-xs text-foreground transition-colors hover:bg-secondary cursor-pointer"
                  :class="{ 'font-semibold bg-accent text-accent-foreground': locale === 'sw' }"
                  @click="switchLocale('sw')"
                >
                  {{ t("shell.language_name_sw") }}
                </button>
              </PopoverContent>
            </Popover>

            <!-- Display Menu Popover -->
            <DisplayMenu />

            <!-- Notifications Center Popover -->
            <Popover>
              <PopoverTrigger as-child>
                <button
                  type="button"
                  class="relative inline-flex size-7 items-center justify-center rounded-md border border-transparent text-muted-foreground transition-colors hover:border-border hover:bg-secondary hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring cursor-pointer"
                  :aria-label="
                    unreadNotificationCount > 0
                      ? t('shell.notifications_unread', { count: unreadNotificationCount })
                      : t('shell.notifications_title')
                  "
                >
                  <Bell class="size-4" aria-hidden="true" />
                  <span
                    v-if="unreadNotificationCount > 0"
                    class="absolute right-1 top-1 size-1.5 rounded-full bg-critical ring-2 ring-surface"
                    aria-hidden="true"
                  />
                </button>
              </PopoverTrigger>
              <PopoverContent class="w-80 p-0 shadow-lg" align="end">
                <div class="flex items-center justify-between border-b border-border px-3 py-2">
                  <div class="flex items-center gap-1.5">
                    <span class="text-xs font-semibold text-foreground">
                      {{ t("shell.notifications_title") }}
                    </span>
                    <Badge v-if="unreadNotificationCount > 0" variant="critical" class="px-1 py-0 text-[10px] font-mono">
                      {{ unreadNotificationCount }}
                    </Badge>
                  </div>
                  <button
                    v-if="unreadNotificationCount > 0"
                    type="button"
                    class="text-[11px] text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                    @click="unreadNotificationCount = 0"
                  >
                    {{ t("shell.mark_all_read") }}
                  </button>
                </div>
                <div class="py-6 px-4 text-center text-xs text-muted-foreground">
                  {{ t("shell.notifications_empty") }}
                </div>
              </PopoverContent>
            </Popover>

            <div class="h-6 w-px bg-border" aria-hidden="true" />

            <!-- Profile / Account Menu -->
            <Popover>
              <PopoverTrigger as-child>
                <button
                  type="button"
                  class="flex items-center gap-2 rounded-md px-1.5 py-1 text-left transition-colors hover:bg-secondary cursor-pointer"
                  :aria-label="t('shell.profile_menu')"
                >
                  <span
                    class="grid size-7 place-items-center rounded-full bg-primary text-[11px] font-semibold text-primary-foreground select-none shadow-xs"
                  >
                    {{ userInitials }}
                  </span>
                  <span class="hidden min-w-0 leading-tight sm:block">
                    <span class="block truncate text-[12.5px] font-medium text-foreground">
                      {{ userDisplayName }}
                    </span>
                    <span class="flex items-center gap-1 text-[10.5px] text-muted-foreground">
                      <span class="relative flex size-1.5 shrink-0">
                        <span class="relative size-1.5 rounded-full" :class="isOffline ? 'bg-warning' : 'bg-success'" />
                      </span>
                      {{ isOffline ? t("shell.offline") : t("shell.online") }}
                    </span>
                  </span>
                  <ChevronDown class="size-3 shrink-0 opacity-60" aria-hidden="true" />
                </button>
              </PopoverTrigger>
              <PopoverContent class="w-60 p-2 shadow-lg" align="end">
                <div class="border-b border-border pb-2.5 px-1">
                  <div class="flex items-center gap-2">
                    <span
                      class="grid size-8 place-items-center rounded-full bg-primary text-[12px] font-semibold text-primary-foreground select-none shrink-0"
                    >
                      {{ userInitials }}
                    </span>
                    <div class="min-w-0 flex-1">
                      <p class="truncate text-xs font-semibold text-foreground">
                        {{ userDisplayName }}
                      </p>
                      <p class="truncate text-[11px] text-muted-foreground font-mono">
                        {{ auth?.user?.email || "reception@afyanova.tz" }}
                      </p>
                    </div>
                  </div>
                  <div class="mt-2.5 flex items-center justify-between gap-1 text-[10.5px] text-muted-foreground">
                    <span class="truncate font-medium text-foreground">
                      {{ scopeLabel || "Dar Main Hospital" }}
                    </span>
                    <Badge variant="outline" class="text-[9px] uppercase tracking-wide font-mono px-1 py-0">
                      {{ auth?.isPlatformSuperAdmin ? "Superadmin" : "Staff" }}
                    </Badge>
                  </div>
                </div>
                <div class="pt-1.5">
                  <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-xs text-critical hover:bg-critical/10 transition-colors cursor-pointer"
                    @click="signOut"
                  >
                    <LogOut class="size-3.5" aria-hidden="true" />
                    {{ t("shell.sign_out") }}
                  </button>
                </div>
              </PopoverContent>
            </Popover>
          </div>
        </header>

        <!-- Patient Banner (if present) -->
        <section
          v-if="patientContext"
          class="flex h-10 shrink-0 items-center gap-4 border-b border-border bg-surface px-4"
          :aria-label="t('shell.patient_context')"
        >
          <div class="flex items-center gap-2">
            <span class="text-sm font-semibold text-foreground">
              {{ patientContext.name }}
            </span>
            <span class="text-xs text-muted-foreground font-mono">
              {{ t("patient.mrn") }}: {{ patientContext.mrn }}
            </span>
            <span class="text-xs text-muted-foreground">
              {{ patientContext.age }}y {{ patientContext.sex }}
            </span>
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

        <!-- Main Workspace View (Density-driven dynamic padding) -->
        <main
          class="flex min-h-0 flex-1 overflow-hidden bg-background transition-[padding] duration-150"
          :class="contentPaddingClass"
          tabindex="-1"
        >
          <slot />
        </main>

        <!-- Status Bar / Footer (aligned to right column) -->
        <footer
          class="flex h-7 shrink-0 items-center gap-4 border-t border-border bg-surface px-3 text-[11px] text-muted-foreground"
        >
          <span class="flex items-center gap-1.5">
            <span class="relative flex size-1.5 shrink-0">
              <span class="absolute inset-0 animate-ping rounded-full opacity-70" :class="isOffline ? 'bg-warning' : 'bg-success'" />
              <span class="relative size-1.5 rounded-full" :class="isOffline ? 'bg-warning' : 'bg-success'" />
            </span>
            {{ isOffline ? t("shell.offline") : t("shell.live_synced") }}
          </span>

          <span class="hidden items-center gap-1.5 sm:flex">
            {{ t("shell.sla_breaches") }}
            <span class="num font-mono font-semibold" :class="slaBreaches > 0 ? 'text-critical' : 'text-foreground'">
              {{ slaBreaches }}
            </span>
          </span>

          <span class="hidden items-center gap-1.5 sm:flex">
            {{ t("shell.avg_wait") }}
            <span class="num font-mono font-semibold text-foreground">{{ avgWait }}</span>
          </span>

          <span class="hidden items-center gap-1.5 md:flex">
            {{ t("shell.patients_on_file") }}
            <span class="num font-mono font-semibold text-foreground">{{ totalPatientsCount }}</span>
          </span>

          <span class="ml-auto hidden items-center gap-1.5 md:flex">
            <kbd class="rounded border border-border bg-secondary px-1 py-px font-mono text-[10px] font-medium text-muted-foreground">⌘K</kbd>
            {{ t("shell.search") }}
            <span class="text-border-strong font-bold">·</span>
            <kbd class="rounded border border-border bg-secondary px-1 py-px font-mono text-[10px] font-medium text-muted-foreground">N</kbd>
            {{ t("shell.new_patient") }}
          </span>
        </footer>
      </div>

      <!-- Teleported Global Command Palette -->
      <CommandPalette />
    </div>
  </TooltipProvider>
</template>
