<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import {
  Activity,
  ArrowRight,
  Building2,
  Calendar,
  Check,
  ClipboardList,
  Clock,
  Command,
  CornerDownLeft,
  FileText,
  HeartPulse,
  LayoutDashboard,
  Moon,
  Pill,
  Pin,
  RotateCcw,
  Search,
  Sparkles,
  Sun,
  User,
  UserPlus,
  Users,
  X,
} from "lucide-vue-next";
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { usePatientStore, type Patient } from "@/stores/patientStore";
import { useRecentStore, type RecentItem } from "@/stores/recentStore";
import { useUiStore } from "@/stores/uiStore";

const emit = defineEmits<{
  selectPatient: [patient: Patient];
  executeAction: [action: string];
}>();

const { t } = useI18n();
const uiStore = useUiStore();
const recentStore = useRecentStore();
const patientStore = usePatientStore();

const isOpen = computed({
  get: () => uiStore.commandPaletteOpen,
  set: (val: boolean) => {
    if (val) uiStore.openCommandPalette();
    else uiStore.closeCommandPalette();
  },
});

const searchQuery = ref("");
const isLoading = ref(false);
const searchResults = ref<Patient[]>([]);
const selectedIndex = ref(0);
const inputRef = ref<HTMLInputElement | null>(null);
const listContainerRef = ref<HTMLElement | null>(null);

// Reset state on open
watch(isOpen, (val) => {
  if (val) {
    searchQuery.value = "";
    searchResults.value = [];
    selectedIndex.value = 0;
    nextTick(() => {
      inputRef.value?.focus();
    });
  }
});

let searchDebounce: ReturnType<typeof setTimeout> | undefined;
function onSearchInput() {
  const query = searchQuery.value.trim();
  selectedIndex.value = 0;
  if (!query) {
    searchResults.value = [];
    isLoading.value = false;
    return;
  }

  isLoading.value = true;
  if (searchDebounce) clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    void fetch(`/api/v1/reception/patients/search?q=${encodeURIComponent(query)}`, {
      headers: { "X-Requested-With": "XMLHttpRequest" },
    })
      .then((res) => (res.ok ? (res.json() as Promise<{ data?: Patient[] }>) : null))
      .then((body) => {
        searchResults.value = body?.data ?? [];
      })
      .catch(() => {
        searchResults.value = [];
      })
      .finally(() => {
        isLoading.value = false;
      });
  }, 180);
}

const page = usePage();
const auth = computed(() => (page.props.auth as any) ?? {});

// Workspaces catalog with role and permission gates
const workspaceCatalog = [
  {
    id: "nav-reception",
    labelKey: "nav.reception",
    defaultLabel: "Reception Workspace",
    href: "/reception",
    icon: Users,
    badge: "Front Desk",
    permissions: ["appointment.check-in", "reception.access"],
    roles: ["receptionist", "ADMIN.REGISTRATION", "registration_clerk"],
  },
  {
    id: "nav-nursing",
    labelKey: "nav.nursing",
    defaultLabel: "Nursing Workspace",
    href: "/nursing",
    icon: Activity,
    badge: "Triage",
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
    id: "nav-clinician",
    labelKey: "nav.clinician",
    defaultLabel: "Clinician Workbench",
    href: "/clinician",
    icon: LayoutDashboard,
    badge: "OPD",
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
    id: "nav-pharmacy",
    labelKey: "nav.pharmacy",
    defaultLabel: "Pharmacy Workspace",
    href: "/pharmacy",
    icon: Pill,
    badge: "Dispensary",
    permissions: ["medication.dispense", "pharmacy.access"],
    roles: [
      "dispenser",
      "PHARMACY.STAFF",
      "pharmacist",
      "PHARMACY.SUPERVISOR",
    ],
  },
  {
    id: "nav-billing",
    labelKey: "nav.billing",
    defaultLabel: "Billing & Cashier",
    href: "/cashier",
    icon: Building2,
    badge: "Finance",
    permissions: ["billing.payments.record", "cashier.access"],
    roles: ["cashier", "FINANCE.CASHIER", "accountant"],
  },
];

// Clinical actions catalog
const actionsCatalog = [
  { id: "act-vitals", labelKey: "command_palette.act_vitals", defaultLabel: "Record Patient Vitals", shortcut: "Ctrl+V", icon: Activity },
  { id: "act-assessment", labelKey: "command_palette.act_assessment", defaultLabel: "New Nursing Assessment", shortcut: "Ctrl+A", icon: ClipboardList },
  { id: "act-note", labelKey: "command_palette.act_note", defaultLabel: "New Clinical Note", shortcut: "Ctrl+N", icon: FileText },
  { id: "act-mar", labelKey: "command_palette.act_mar", defaultLabel: "Medication Administration (MAR)", shortcut: "Ctrl+M", icon: Pill },
  { id: "act-register", labelKey: "command_palette.act_register", defaultLabel: "Register New Patient", shortcut: "Ctrl+R", icon: UserPlus },
  { id: "act-theme", labelKey: "command_palette.act_toggle_theme", defaultLabel: "Toggle Theme", shortcut: "Ctrl+T", icon: Sun },
  { id: "act-reset-layout", labelKey: "command_palette.act_reset_layout", defaultLabel: "Reset Panel Widths & Layout", icon: RotateCcw },
];

// Visible workspaces filtered by user RBAC
const accessibleWorkspaces = computed(() => {
  const permissions = (auth.value?.permissions ?? []).map((p: string) => p.toLowerCase());
  const roleCodes = (auth.value?.roleCodes ?? []).map((r: string) => r.toLowerCase());
  const isSuperAdmin =
    auth.value?.isPlatformSuperAdmin === true ||
    auth.value?.isFacilitySuperAdmin === true ||
    roleCodes.includes("super_admin") ||
    roleCodes.includes("admin.facility");

  if (isSuperAdmin) {
    return workspaceCatalog;
  }

  return workspaceCatalog.filter((item) => {
    if (!item.permissions && !item.roles) return true;
    if (item.permissions?.some((p) => permissions.includes(p.toLowerCase()))) return true;
    if (item.roles?.some((r) => roleCodes.includes(r.toLowerCase()))) return true;
    return false;
  });
});

// Filtered workspaces by search query
const filteredWorkspaces = computed(() => {
  const query = searchQuery.value.trim().toLowerCase();
  if (!query) return accessibleWorkspaces.value;
  return accessibleWorkspaces.value.filter((item) => {
    const label = t(item.labelKey) || item.defaultLabel;
    return label.toLowerCase().includes(query) || item.href.toLowerCase().includes(query) || item.badge.toLowerCase().includes(query);
  });
});

// Filtered actions
const filteredActions = computed(() => {
  const query = searchQuery.value.trim().toLowerCase();
  if (!query) return actionsCatalog;
  return actionsCatalog.filter((item) => {
    const label = t(item.labelKey) || item.defaultLabel;
    return label.toLowerCase().includes(query) || (item.shortcut && item.shortcut.toLowerCase().includes(query));
  });
});

// All selectable flat items for arrow key navigation
interface PaletteItem {
  type: "patient" | "recent" | "action" | "workspace";
  data: any;
}

const flatItems = computed<PaletteItem[]>(() => {
  const items: PaletteItem[] = [];
  const query = searchQuery.value.trim();

  if (query) {
    // 1. Patient search results
    for (const p of searchResults.value) {
      items.push({ type: "patient", data: p });
    }
    // 2. Filtered actions
    for (const a of filteredActions.value) {
      items.push({ type: "action", data: a });
    }
    // 3. Filtered workspaces
    for (const w of filteredWorkspaces.value) {
      items.push({ type: "workspace", data: w });
    }
  } else {
    // 1. Recent & Pinned Patients
    const recents = recentStore.items || [];
    for (const p of recents.slice(0, 4)) {
      items.push({ type: "recent", data: p });
    }
    // 2. Quick Clinical Actions
    for (const a of actionsCatalog.slice(0, 5)) {
      items.push({ type: "action", data: a });
    }
    // 3. Workspaces
    for (const w of workspaceCatalog) {
      items.push({ type: "workspace", data: w });
    }
  }
  return items;
});

// Scroll active item into view
function scrollSelectedIntoView() {
  nextTick(() => {
    if (!listContainerRef.value) return;
    const activeEl = listContainerRef.value.querySelector<HTMLElement>("[data-active='true']");
    if (activeEl) {
      activeEl.scrollIntoView({ block: "nearest" });
    }
  });
}

// Arrow key navigation inside palette
function onPaletteKeydown(e: KeyboardEvent) {
  if (!isOpen.value) return;

  const total = flatItems.value.length;
  if (e.key === "ArrowDown") {
    e.preventDefault();
    if (total > 0) {
      selectedIndex.value = (selectedIndex.value + 1) % total;
      scrollSelectedIntoView();
    }
  } else if (e.key === "ArrowUp") {
    e.preventDefault();
    if (total > 0) {
      selectedIndex.value = (selectedIndex.value - 1 + total) % total;
      scrollSelectedIntoView();
    }
  } else if (e.key === "Enter") {
    e.preventDefault();
    if (total > 0 && selectedIndex.value >= 0 && selectedIndex.value < total) {
      executeItem(flatItems.value[selectedIndex.value]);
    }
  }
}

function executeItem(item: PaletteItem) {
  if (!item) return;

  if (item.type === "patient") {
    const patient = item.data as Patient;
    recentStore.addRecent(patient);
    patientStore.selectPatient(patient.id);
    emit("selectPatient", patient);
    uiStore.closeCommandPalette();
  } else if (item.type === "recent") {
    const recent = item.data as RecentItem;
    patientStore.selectPatient(recent.id);
    uiStore.closeCommandPalette();
  } else if (item.type === "action") {
    const action = item.data;
    if (action.id === "act-theme") {
      uiStore.setTheme(uiStore.theme === "dark" ? "light" : "dark");
    } else if (action.id === "act-reset-layout") {
      window.dispatchEvent(new CustomEvent("afyanova:reset-split-panes"));
    } else {
      emit("executeAction", action.id);
    }
    uiStore.closeCommandPalette();
  } else if (item.type === "workspace") {
    const ws = item.data;
    router.visit(ws.href);
    uiStore.closeCommandPalette();
  }
}

// Global shortcut listener
function handleGlobalKeydown(e: KeyboardEvent) {
  const isInput =
    e.target instanceof HTMLInputElement ||
    e.target instanceof HTMLTextAreaElement ||
    (e.target as HTMLElement)?.isContentEditable;

  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
    e.preventDefault();
    uiStore.commandPaletteOpen ? uiStore.closeCommandPalette() : uiStore.openCommandPalette();
  } else if (!isInput && !e.ctrlKey && !e.metaKey && !e.altKey && e.key === "/") {
    e.preventDefault();
    uiStore.openCommandPalette();
  } else if (e.key === "Escape" && uiStore.commandPaletteOpen) {
    uiStore.closeCommandPalette();
  }
}

onMounted(() => {
  window.addEventListener("keydown", handleGlobalKeydown);
});

onUnmounted(() => {
  window.removeEventListener("keydown", handleGlobalKeydown);
  if (searchDebounce) clearTimeout(searchDebounce);
});

function patientFullName(p: Patient): string {
  const given = p.name?.[0]?.given?.join(" ") || "";
  const family = p.name?.[0]?.family || "";
  return `${given} ${family}`.trim() || "Unknown Patient";
}

function patientInitials(name: string): string {
  const parts = name.trim().split(/\s+/);
  if (parts.length === 0) return "PT";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function patientAge(birthDate?: string): string {
  if (!birthDate) return "";
  const diff = Date.now() - new Date(birthDate).getTime();
  const years = Math.floor(diff / (365.25 * 24 * 60 * 60 * 1000));
  return `${years}y`;
}
</script>

<template>
  <Dialog v-model:open="isOpen">
    <DialogContent
      :show-close-button="false"
      class="overflow-hidden p-0 sm:max-w-2xl lg:max-w-3xl max-w-[94vw] w-full rounded-xl border border-border bg-surface-raised shadow-2xl transition-all"
    >
      <DialogTitle class="sr-only">Afyanova Clinical Command Palette</DialogTitle>
      <DialogDescription class="sr-only">Search patients by Name or MRN, run actions, or jump to workspaces.</DialogDescription>

      <!-- Search Input Header -->
      <div class="flex items-center border-b border-border px-4 py-3 bg-surface">
        <Search class="mr-3 size-4.5 shrink-0 text-muted-foreground" aria-hidden="true" />
        <input
          ref="inputRef"
          v-model="searchQuery"
          type="text"
          class="flex h-9 w-full rounded-md bg-transparent text-sm font-medium outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50 text-foreground"
          :placeholder="t('command_palette.placeholder')"
          @input="onSearchInput"
          @keydown="onPaletteKeydown"
        />
        <div class="flex items-center gap-1.5 ml-2 shrink-0">
          <button
            v-if="searchQuery.trim()"
            type="button"
            class="size-5 inline-flex items-center justify-center rounded-full text-muted-foreground hover:bg-secondary hover:text-foreground transition-colors cursor-pointer"
            @click="searchQuery = ''; searchResults = []"
          >
            <X class="size-3.5" aria-hidden="true" />
          </button>
          <Badge variant="outline" class="font-mono text-[9.5px] px-1.5 py-0 text-muted-foreground uppercase border-border/80">
            ESC
          </Badge>
        </div>
      </div>

      <!-- Command List Scroll Container -->
      <div
        ref="listContainerRef"
        class="max-h-[60vh] overflow-y-auto p-2.5 space-y-3 focus:outline-none"
        tabindex="-1"
      >
        <!-- ================================================================
             1. PATIENT SEARCH RESULTS (When typing)
             ================================================================ -->
        <div v-if="searchQuery.trim()">
          <div class="flex items-center justify-between px-2 py-1">
            <span class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
              {{ t("command_palette.section_patients") }}
            </span>
            <span v-if="!isLoading && searchResults.length > 0" class="text-[11px] text-muted-foreground font-mono">
              {{ searchResults.length }} {{ t("command_palette.section_patients").toLowerCase() }}
            </span>
          </div>

          <div v-if="isLoading" class="py-6 text-center text-xs text-muted-foreground flex items-center justify-center gap-2">
            <Activity class="size-4 animate-pulse text-primary" />
            <span>{{ t("command_palette.searching") }}</span>
          </div>

          <div
            v-else-if="searchResults.length === 0"
            class="py-4 px-2 text-center text-xs text-muted-foreground"
          >
            {{ t("command_palette.no_results") }}
          </div>

          <div v-else class="space-y-1 mt-1">
            <button
              v-for="(patient, pIdx) in searchResults"
              :key="patient.id"
              type="button"
              :data-active="selectedIndex === pIdx"
              class="group relative flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-xs transition-all cursor-pointer gap-3"
              :class="[
                selectedIndex === pIdx
                  ? 'bg-accent font-medium text-accent-foreground shadow-xs'
                  : 'text-foreground hover:bg-secondary/80',
              ]"
              @click="executeItem({ type: 'patient', data: patient })"
              @mouseenter="selectedIndex = pIdx"
            >
              <div class="flex items-center gap-2.5 min-w-0 flex-1">
                <Avatar class="size-7 shrink-0">
                  <AvatarFallback class="text-[10px] bg-primary/10 text-primary font-semibold">
                    {{ patientInitials(patientFullName(patient)) }}
                  </AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-1.5">
                    <span class="font-semibold truncate text-[13px]">
                      {{ patientFullName(patient) }}
                    </span>
                    <Badge v-if="patient.gender" variant="outline" class="px-1 py-0 text-[9px] uppercase font-mono">
                      {{ patient.gender.slice(0, 1) }}
                    </Badge>
                    <span v-if="patient.birthDate" class="text-[11px] text-muted-foreground">
                      {{ patientAge(patient.birthDate) }}
                    </span>
                  </div>
                  <div class="flex items-center gap-2 text-[11px] text-muted-foreground mt-0.5 font-mono">
                    <span>MRN: {{ patient.identifier?.[0]?.value || "—" }}</span>
                    <span v-if="patient.telecom?.[0]?.value">· {{ patient.telecom[0].value }}</span>
                  </div>
                </div>
              </div>

              <div class="flex items-center gap-1 shrink-0">
                <Badge
                  variant="secondary"
                  class="text-[10px] transition-colors"
                  :class="selectedIndex === pIdx ? 'bg-primary text-primary-foreground' : ''"
                >
                  {{ t("command_palette.select_hint") }}
                </Badge>
                <CornerDownLeft v-if="selectedIndex === pIdx" class="size-3 text-muted-foreground" />
              </div>
            </button>
          </div>
        </div>

        <!-- ================================================================
             2. RECENT / PINNED PATIENTS (When search is empty)
             ================================================================ -->
        <div v-if="!searchQuery.trim() && recentStore.items && recentStore.items.length > 0">
          <p class="px-2 py-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
            {{ t("command_palette.section_recent_patients") }}
          </p>
          <div class="space-y-1 mt-1">
            <button
              v-for="(recent, rIdx) in (recentStore.items || []).slice(0, 4)"
              :key="recent.id"
              type="button"
              :data-active="selectedIndex === rIdx"
              class="group flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-xs transition-all cursor-pointer gap-3"
              :class="[
                selectedIndex === rIdx
                  ? 'bg-accent font-medium text-accent-foreground shadow-xs'
                  : 'text-foreground hover:bg-secondary/80',
              ]"
              @click="executeItem({ type: 'recent', data: recent })"
              @mouseenter="selectedIndex = rIdx"
            >
              <div class="flex items-center gap-2.5 min-w-0 flex-1">
                <Avatar class="size-7 shrink-0">
                  <AvatarFallback class="text-[10px] bg-secondary text-foreground font-semibold">
                    {{ patientInitials(recent.name) }}
                  </AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-1.5">
                    <span class="font-medium truncate text-[12.5px]">
                      {{ recent.name }}
                    </span>
                    <Pin v-if="recent.pinned" class="size-3 text-primary shrink-0" aria-hidden="true" />
                  </div>
                  <p class="text-[10.5px] text-muted-foreground font-mono">
                    MRN: {{ recent.mrn }}
                  </p>
                </div>
              </div>
              <Badge variant="outline" class="text-[9.5px] text-muted-foreground font-mono">
                Recent
              </Badge>
            </button>
          </div>
        </div>

        <!-- ================================================================
             3. CLINICAL ACTIONS
             ================================================================ -->
        <div v-if="filteredActions.length > 0">
          <p class="px-2 py-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
            {{ t("command_palette.section_actions") }}
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 mt-1">
            <button
              v-for="(act, actIdx) in filteredActions"
              :key="act.id"
              type="button"
              :data-active="selectedIndex === (searchQuery.trim() ? searchResults.length + actIdx : (!searchQuery.trim() && (recentStore.items?.length ?? 0) > 0 ? Math.min(4, recentStore.items.length) + actIdx : actIdx))"
              class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-xs transition-all cursor-pointer gap-2"
              :class="[
                selectedIndex === (searchQuery.trim() ? searchResults.length + actIdx : (!searchQuery.trim() && (recentStore.items?.length ?? 0) > 0 ? Math.min(4, recentStore.items.length) + actIdx : actIdx))
                  ? 'bg-accent font-medium text-accent-foreground shadow-xs'
                  : 'text-foreground hover:bg-secondary/80',
              ]"
              @click="executeItem({ type: 'action', data: act })"
              @mouseenter="selectedIndex = (searchQuery.trim() ? searchResults.length + actIdx : (!searchQuery.trim() && (recentStore.items?.length ?? 0) > 0 ? Math.min(4, recentStore.items.length) + actIdx : actIdx))"
            >
              <div class="flex items-center gap-2.5 min-w-0 flex-1">
                <component :is="act.icon" class="size-4 text-primary shrink-0" aria-hidden="true" />
                <span class="truncate">{{ t(act.labelKey) || act.defaultLabel }}</span>
              </div>
              <kbd
                v-if="act.shortcut"
                class="rounded border border-border/80 bg-card px-1.5 py-0.5 font-mono text-[9.5px] text-muted-foreground shrink-0 shadow-xs"
              >
                {{ act.shortcut }}
              </kbd>
            </button>
          </div>
        </div>

        <!-- ================================================================
             4. WORKSPACES & MODULES
             ================================================================ -->
        <div v-if="filteredWorkspaces.length > 0">
          <p class="px-2 py-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
            {{ t("command_palette.section_workspaces") }}
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 mt-1">
            <button
              v-for="(nav, navIdx) in filteredWorkspaces"
              :key="nav.id"
              type="button"
              :data-active="selectedIndex === (flatItems.length - filteredWorkspaces.length + navIdx)"
              class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-xs transition-all cursor-pointer gap-2"
              :class="[
                selectedIndex === (flatItems.length - filteredWorkspaces.length + navIdx)
                  ? 'bg-accent font-medium text-accent-foreground shadow-xs'
                  : 'text-foreground hover:bg-secondary/80',
              ]"
              @click="executeItem({ type: 'workspace', data: nav })"
              @mouseenter="selectedIndex = (flatItems.length - filteredWorkspaces.length + navIdx)"
            >
              <div class="flex items-center gap-2.5 min-w-0 flex-1">
                <component :is="nav.icon" class="size-4 text-muted-foreground group-hover:text-primary shrink-0" aria-hidden="true" />
                <span class="truncate">{{ t(nav.labelKey) || nav.defaultLabel }}</span>
              </div>
              <div class="flex items-center gap-1.5 shrink-0">
                <Badge variant="outline" class="text-[9px] font-mono px-1 py-0 text-muted-foreground border-border/60">
                  {{ nav.badge }}
                </Badge>
                <ArrowRight class="size-3 text-muted-foreground" aria-hidden="true" />
              </div>
            </button>
          </div>
        </div>
      </div>

      <!-- Ergonomic 2027 Raycast/Linear Keyboard Helper Footer -->
      <div class="flex items-center justify-between border-t border-border bg-surface px-4 py-2 text-[11px] text-muted-foreground select-none">
        <div class="flex items-center gap-3">
          <span class="flex items-center gap-1">
            <kbd class="rounded border border-border bg-card px-1 font-mono text-[10px]">↑</kbd>
            <kbd class="rounded border border-border bg-card px-1 font-mono text-[10px]">↓</kbd>
            <span>{{ t("command_palette.navigate_hint") }}</span>
          </span>
          <span class="flex items-center gap-1">
            <kbd class="rounded border border-border bg-card px-1 font-mono text-[10px]">↵</kbd>
            <span>{{ t("command_palette.select_hint") }}</span>
          </span>
          <span class="hidden sm:inline-flex items-center gap-1">
            <kbd class="rounded border border-border bg-card px-1 font-mono text-[10px]">esc</kbd>
            <span>{{ t("command_palette.close_hint") }}</span>
          </span>
        </div>
        <div class="flex items-center gap-1 text-[10.5px]">
          <Sparkles class="size-3 text-primary" aria-hidden="true" />
          <span>Afyanova Clinical Command</span>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>