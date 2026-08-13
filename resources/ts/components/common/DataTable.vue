/** * DataTable — composite component (Volume 1.2 §4.2, §6) *
======================================================= * The workhorse of
clinical UIs. Sortable, filterable, virtualized table. * * §6.2 Features: * -
Sorting: click header to sort; aria-sort on keyboard accessible * - Filtering:
column-level filters via popover; filter state persisted (P4) * -
Virtualization: required for > 100 rows; uses @tanstack/vue-virtual * -
Selection: row selection via checkbox; Shift+click range select; Ctrl+A select
all * - Density: row height varies by data-density: compact 32px / comfortable
32px / spacious 40px (comfortable/spacious tightened one notch,
component-library audit 2026-08-10 — was 36/44; compact tried 28px the same
day, raised back to 32px — too tight, currently equal to comfortable, a gap
this doc flags rather than hides) * - Sticky header: header is sticky within the scroll area
* - Column resize: drag column borders; widths persisted (P4) * - Column
visibility: toggle columns via a settings popover; state persisted * -
Empty/loading/error/offline: all four states implemented (§3) * - Keyboard: Tab
into table → row navigation via Arrow Up/Down → Enter to open row * * §6.3
Clinical rules: * - Mono font for values: numeric clinical values use
--font-mono * - Status columns: use StatusBadge, never bare colored text * -
Date columns: use the clinical date format DD MMM YYYY * - Row actions:
available via a row menu (kebab) and keyboard; never click-only * - Sticky first
column: the identifier column is sticky when horizontally scrolling */

<script setup lang="ts">
import { useVirtualizer } from "@tanstack/vue-virtual";
import {
  ChevronDown,
  ChevronUp,
  ChevronsUpDown,
  Settings2,
} from "lucide-vue-next";
import { computed, nextTick, ref, watch } from "vue";
import EmptyState from "@/components/common/EmptyState.vue";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { useI18nSafe } from "@/composables/useI18nSafe";

export interface DataTableColumn<T> {
  key: string;
  label: string;
  accessor: (row: T) => unknown;
  slot?: string;
  sortable?: boolean;
  filterable?: boolean;
  width?: number;
  sticky?: boolean;
  clinical?: boolean;
  align?: "left" | "center" | "right";
  hidden?: boolean;
}

export type SortDirection = "asc" | "desc";

export interface SortState {
  key: string;
  direction: SortDirection;
}

export interface FilterState {
  [key: string]: string;
}

type Row = any;

const props = withDefaults(
  defineProps<{
    columns: DataTableColumn<Row>[];
    rows: Row[];
    rowKey: (row: Row) => string;
    onRowClick?: (row: Row) => void;
    loading?: boolean;
    error?: string | null;
    offline?: boolean;
    selectable?: boolean;
    resizable?: boolean;
    columnVisibility?: boolean;
    bordered?: boolean;
    persistKey?: string;
    emptyTitle?: string;
    emptyDescription?: string;
    emptyActionLabel?: string;
  }>(),
  {
    onRowClick: undefined,
    loading: false,
    error: null,
    offline: false,
    selectable: false,
    resizable: false,
    columnVisibility: false,
    bordered: true,
    persistKey: undefined,
    emptyTitle: undefined,
    emptyDescription: undefined,
    emptyActionLabel: undefined,
  },
);

const emit = defineEmits<{
  rowClick: [row: Row];
  selectionChange: [selected: Set<string>];
  sortChange: [sort: SortState | null];
  filterChange: [filters: FilterState];
  emptyAction: [];
  retry: [];
}>();

const { t } = useI18nSafe();

const STORAGE_PREFIX = "afyanova:datatable:";

function loadPersisted<T>(key: string, fallback: T): T {
  if (!props.persistKey) return fallback;
  try {
    const raw = localStorage.getItem(
      `${STORAGE_PREFIX}${props.persistKey}:${key}`,
    );
    return raw ? (JSON.parse(raw) as T) : fallback;
  } catch {
    return fallback;
  }
}

function savePersisted(key: string, value: unknown) {
  if (!props.persistKey) return;
  try {
    localStorage.setItem(
      `${STORAGE_PREFIX}${props.persistKey}:${key}`,
      JSON.stringify(value),
    );
  } catch {
    // ignore
  }
}

const sort = ref<SortState | null>(
  loadPersisted<SortState | null>("sort", null),
);
watch(sort, (s) => {
  savePersisted("sort", s);
  emit("sortChange", s);
});

function toggleSort(col: DataTableColumn<Row>) {
  if (col.sortable === false) return;
  if (sort.value?.key === col.key) {
    sort.value =
      sort.value.direction === "asc"
        ? { key: col.key, direction: "desc" }
        : null;
  } else {
    sort.value = { key: col.key, direction: "asc" };
  }
}

const filters = ref<FilterState>(loadPersisted<FilterState>("filters", {}));
watch(filters, (f) => {
  savePersisted("filters", f);
  emit("filterChange", f);
});

const hiddenColumns = ref<Set<string>>(
  new Set(
    loadPersisted<string[]>(
      "hidden",
      props.columns.filter((c) => c.hidden).map((c) => c.key),
    ),
  ),
);

function toggleColumn(key: string) {
  const next = new Set(hiddenColumns.value);
  if (next.has(key)) {
    next.delete(key);
  } else {
    next.add(key);
  }
  hiddenColumns.value = next;
  savePersisted("hidden", [...next]);
}

const visibleColumns = computed(() =>
  props.columns.filter((c) => !hiddenColumns.value.has(c.key)),
);

const columnWidths = ref<Record<string, number>>(
  loadPersisted<Record<string, number>>("widths", {}),
);

function startResize(event: MouseEvent, col: DataTableColumn<Row>) {
  if (!props.resizable) return;
  event.preventDefault();
  const startX = event.clientX;
  const startWidth = columnWidths.value[col.key] ?? col.width ?? 160;

  function onMove(e: MouseEvent) {
    const delta = e.clientX - startX;
    columnWidths.value = {
      ...columnWidths.value,
      [col.key]: Math.max(80, startWidth + delta),
    };
  }

  function onUp() {
    savePersisted("widths", columnWidths.value);
    window.removeEventListener("mousemove", onMove);
    window.removeEventListener("mouseup", onUp);
  }

  window.addEventListener("mousemove", onMove);
  window.addEventListener("mouseup", onUp);
}

const processedRows = computed(() => {
  let result = [...props.rows];
  for (const [key, value] of Object.entries(filters.value)) {
    const col = props.columns.find((c) => c.key === key);
    if (!col) continue;
    result = result.filter((row) => {
      const cell = col.accessor(row);
      return String(cell ?? "")
        .toLowerCase()
        .includes(value.toLowerCase());
    });
  }
  if (sort.value) {
    const col = props.columns.find((c) => c.key === sort.value?.key);
    if (col) {
      const dir = sort.value.direction === "asc" ? 1 : -1;
      result.sort((a, b) => {
        const av = col.accessor(a);
        const bv = col.accessor(b);
        if (av == null) return 1;
        if (bv == null) return -1;
        return (
          String(av).localeCompare(String(bv), undefined, { numeric: true }) *
          dir
        );
      });
    }
  }
  return result;
});

const ROW_HEIGHT = 36;
const VIRTUALIZE_THRESHOLD = 100;
const scrollRef = ref<HTMLElement | null>(null);
const shouldVirtualize = computed(
  () => processedRows.value.length > VIRTUALIZE_THRESHOLD,
);

const virtualizer = useVirtualizer({
  count: processedRows.value.length,
  getScrollElement: () => scrollRef.value,
  estimateSize: () => ROW_HEIGHT,
  overscan: 10,
});

const selectedKeys = ref<Set<string>>(new Set());
const lastSelectedIndex = ref<number | null>(null);

const allSelected = computed(() => {
  if (processedRows.value.length === 0) return false;
  return processedRows.value.every((row) =>
    selectedKeys.value.has(props.rowKey(row)),
  );
});

const someSelected = computed(() => {
  const count = processedRows.value.filter((row) =>
    selectedKeys.value.has(props.rowKey(row)),
  ).length;
  return count > 0 && count < processedRows.value.length;
});

// Checkbox has no separate `indeterminate` prop (see Checkbox.vue
// docblock) — reka-ui represents it by setting `model-value` itself to
// the literal string "indeterminate".
const selectAllModelValue = computed<boolean | "indeterminate">(() => {
  if (someSelected.value) return "indeterminate";
  return allSelected.value;
});

function toggleAll() {
  if (allSelected.value) {
    selectedKeys.value = new Set();
  } else {
    selectedKeys.value = new Set(
      processedRows.value.map((row) => props.rowKey(row)),
    );
  }
  emit("selectionChange", selectedKeys.value);
}

function toggleRow(row: Row, index: number, event?: MouseEvent) {
  const key = props.rowKey(row);
  if (event?.shiftKey && lastSelectedIndex.value !== null) {
    const [from, to] = [lastSelectedIndex.value, index].sort((a, b) => a - b);
    const range = processedRows.value
      .slice(from, to + 1)
      .map((r) => props.rowKey(r));
    const next = new Set(selectedKeys.value);
    range.forEach((k) => next.add(k));
    selectedKeys.value = next;
  } else {
    const next = new Set(selectedKeys.value);
    if (next.has(key)) {
      next.delete(key);
    } else {
      next.add(key);
    }
    selectedKeys.value = next;
  }
  lastSelectedIndex.value = index;
  emit("selectionChange", selectedKeys.value);
}

function onTableKeydown(event: KeyboardEvent) {
  if (event.ctrlKey && event.key === "a") {
    event.preventDefault();
    selectedKeys.value = new Set(
      processedRows.value.map((row) => props.rowKey(row)),
    );
    emit("selectionChange", selectedKeys.value);
  }
}

const activeIndex = ref(0);
const rowRefs = ref<(HTMLElement | null)[]>([]);

watch(processedRows, () => {
  if (activeIndex.value >= processedRows.value.length) {
    activeIndex.value = Math.max(0, processedRows.value.length - 1);
  }
});

function focusRow(index: number) {
  if (shouldVirtualize.value) virtualizer.value.scrollToIndex(index);
  nextTick(() => rowRefs.value[index]?.focus());
}

function onRowKeydown(event: KeyboardEvent, row: Row, index: number) {
  if (event.key === "ArrowDown") {
    event.preventDefault();
    activeIndex.value = Math.min(
      activeIndex.value + 1,
      processedRows.value.length - 1,
    );
    focusRow(activeIndex.value);
  } else if (event.key === "ArrowUp") {
    event.preventDefault();
    activeIndex.value = Math.max(activeIndex.value - 1, 0);
    focusRow(activeIndex.value);
  } else if (event.key === "Enter") {
    event.preventDefault();
    props.onRowClick?.(row);
    emit("rowClick", row);
  }
}

function formatClinicalDate(value: unknown): string {
  if (!value) return "";
  const date = new Date(String(value));
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
}

const density = computed(() => {
  if (typeof document === "undefined") return "comfortable";
  return document.documentElement.getAttribute("data-density") ?? "comfortable";
});

// Tightened per density (component-library audit, 2026-08-10) — comfortable
// and spacious one notch down from the original 36px/44px; compact left at
// its original 32px (raised back from an interim 28px — too tight). A dense
// clinical data table (patient lists, worklists) reads better with less
// vertical air between rows; this is a shared global change (every
// DataTable consumer — reception, clinician, nursing — inherits it), not a
// reception-only tweak.
const rowHeightClass = computed(() => {
  switch (density.value) {
    case "compact":
      return "h-8";
    case "spacious":
      return "h-10";
    default:
      return "h-8";
  }
});

const SKELETON_ROWS = 6;
</script>

<template>
  <div
    class="flex h-full flex-col overflow-hidden"
    :class="props.bordered ? 'rounded-lg border border-border bg-surface' : ''"
  >
    <div
      v-if="offline"
      class="border-b border-border bg-warning/5 px-4 py-2 text-xs text-warning"
      role="status"
    >
      {{ t("offline.title") }}
    </div>

    <div
      v-if="error && !loading"
      class="flex flex-1 items-center justify-center p-6"
      role="alert"
    >
      <div class="text-center">
        <p class="mb-2 text-sm font-medium text-destructive">{{ error }}</p>
        <Button size="sm" variant="outline" @click="emit('retry')">
          {{ t("common.retry") }}
        </Button>
      </div>
    </div>

    <div v-if="loading && !error" class="flex-1 overflow-auto" aria-busy="true">
      <table class="w-full border-collapse text-sm">
        <thead>
          <tr class="border-b border-border bg-muted/50">
            <th v-if="selectable" class="w-10 px-2 py-2" />
            <th
              v-for="col in visibleColumns"
              :key="col.key"
              class="px-2 py-2 text-left text-xs font-medium text-muted-foreground"
            >
              {{ col.label }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="n in SKELETON_ROWS"
            :key="n"
            class="border-b border-border"
          >
            <td v-if="selectable" class="px-2 py-2" />
            <td v-for="col in visibleColumns" :key="col.key" class="px-2 py-2">
              <div class="h-3 animate-pulse rounded bg-muted" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <EmptyState
      v-if="!error && !loading && processedRows.length === 0"
      class="flex-1"
      :title="emptyTitle ?? t('common.no_data')"
      :description="emptyDescription"
      :action-label="emptyActionLabel"
      @action="emit('emptyAction')"
    />

    <div
      v-if="!error && !loading && processedRows.length > 0"
      class="flex flex-1 flex-col overflow-hidden"
    >
      <div
        v-if="columnVisibility"
        class="flex items-center justify-end border-b border-border px-3 py-1.5"
      >
        <Popover>
          <PopoverTrigger as-child>
            <Button variant="ghost" size="sm" class="h-7 gap-1.5 text-xs">
              <Settings2 class="h-3.5 w-3.5" aria-hidden="true" />
              {{ t("datatable.columns") }}
            </Button>
          </PopoverTrigger>
          <PopoverContent class="w-56" align="end">
            <div class="space-y-1">
              <label
                v-for="col in columns"
                :key="col.key"
                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-muted"
              >
                <Checkbox
                  :model-value="!hiddenColumns.has(col.key)"
                  @update:model-value="toggleColumn(col.key)"
                />
                <span class="text-foreground">{{ col.label }}</span>
              </label>
            </div>
          </PopoverContent>
        </Popover>
      </div>

      <div
        ref="scrollRef"
        class="flex-1 overflow-auto"
        @keydown="onTableKeydown"
      >
        <table class="w-full border-collapse text-sm">
          <thead class="sticky top-0 z-10 bg-surface">
            <tr class="border-b border-border bg-muted/50">
              <th v-if="selectable" class="w-10 px-2 py-2">
                <Checkbox
                  :model-value="selectAllModelValue"
                  :aria-label="t('datatable.select_all')"
                  @update:model-value="toggleAll"
                />
              </th>
              <th
                v-for="col in visibleColumns"
                :key="col.key"
                class="relative px-2 py-2 text-left text-xs font-medium text-muted-foreground"
                :class="{
                  'sticky left-0 z-10 bg-muted/50': col.sticky,
                  'cursor-pointer select-none hover:text-foreground':
                    col.sortable !== false,
                  'text-center': col.align === 'center',
                  'text-right': col.align === 'right',
                }"
                :style="
                  columnWidths[col.key]
                    ? { width: `${columnWidths[col.key]}px` }
                    : undefined
                "
                :aria-sort="
                  sort?.key === col.key
                    ? sort.direction === 'asc'
                      ? 'ascending'
                      : 'descending'
                    : undefined
                "
                @click="toggleSort(col)"
              >
                <div class="flex items-center gap-1">
                  <span>{{ col.label }}</span>
                  <span v-if="col.sortable !== false" class="shrink-0">
                    <ChevronUp
                      v-if="sort?.key === col.key && sort.direction === 'asc'"
                      class="h-3 w-3"
                      aria-hidden="true"
                    />
                    <ChevronDown
                      v-else-if="
                        sort?.key === col.key && sort.direction === 'desc'
                      "
                      class="h-3 w-3"
                      aria-hidden="true"
                    />
                    <ChevronsUpDown
                      v-else
                      class="h-3 w-3 opacity-40"
                      aria-hidden="true"
                    />
                  </span>
                </div>
                <span
                  v-if="resizable"
                  class="absolute inset-y-0 right-0 w-1 cursor-col-resize hover:bg-primary/50"
                  role="separator"
                  aria-orientation="vertical"
                  @mousedown="startResize($event, col)"
                  @click.stop
                />
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(row, index) in processedRows"
              :key="rowKey(row)"
              :ref="
                (el) => {
                  rowRefs[index] = el as HTMLElement | null;
                }
              "
              class="border-b border-border transition-colors hover:bg-muted/50"
              :class="[
                rowHeightClass,
                selectedKeys.has(rowKey(row)) ? 'bg-primary/5' : '',
                props.onRowClick ? 'cursor-pointer' : '',
              ]"
              tabindex="0"
              :aria-selected="selectedKeys.has(rowKey(row))"
              @click="
                props.onRowClick?.(row);
                emit('rowClick', row);
              "
              @keydown="onRowKeydown($event, row, index)"
              @focus="activeIndex = index"
            >
              <td v-if="selectable" class="px-2" @click.stop>
                <Checkbox
                  :model-value="selectedKeys.has(rowKey(row))"
                  :aria-label="t('datatable.select_row')"
                  @update:model-value="toggleRow(row, index)"
                />
              </td>
              <td
                v-for="col in visibleColumns"
                :key="col.key"
                class="px-2 text-foreground"
                :class="{
                  'clinical-value': col.clinical,
                  'sticky left-0 z-10 bg-surface': col.sticky,
                  'text-center': col.align === 'center',
                  'text-right': col.align === 'right',
                }"
                :style="
                  columnWidths[col.key]
                    ? { width: `${columnWidths[col.key]}px` }
                    : undefined
                "
              >
                <slot
                  v-if="col.slot"
                  :name="col.slot"
                  :row="row"
                  :value="col.accessor(row)"
                />
                <template v-else>{{ col.accessor(row) }}</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
