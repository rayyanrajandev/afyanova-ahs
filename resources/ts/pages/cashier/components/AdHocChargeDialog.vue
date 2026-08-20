<!--
  AdHocChargeDialog — a charge with no clinical order behind it
  =============================================================
  Forms, cards, a service someone walks in for.

  Medicines, lab tests, imaging and procedures are deliberately absent: their
  charge belongs to the order that requested them. A prescriber decides a
  patient needs 21 tablets and a pharmacist decides what is dispensed — a
  cashier typing a quantity here would produce a charge matching no
  prescription. See config/revenue.php.

  The price shown is the resolved one, not the catalogue's face value, because
  that is what the charge will actually be — quoting anything else turns the
  queue into an argument.
-->
<script setup lang="ts">
import { Search } from "lucide-vue-next";
import { ref, watch } from "vue";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useI18nSafe } from "@/composables/useI18nSafe";
import { formatMoney } from "../cashierFormatters";

export interface CatalogItem {
  id: string;
  code: string;
  name: string;
  catalogType: string;
  unit: string | null;
  currencyCode: string;
  unitPrice: string | null;
  isPriced: boolean;
}

const props = defineProps<{ open: boolean; isSubmitting: boolean }>();

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
  (e: "confirm", payload: { chargeableItemId: string; quantity: number }): void;
}>();

const { t } = useI18nSafe();

const search = ref("");
const items = ref<CatalogItem[]>([]);
const selected = ref<CatalogItem | null>(null);
const quantity = ref("1");
const isSearching = ref(false);

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    search.value = "";
    items.value = [];
    selected.value = null;
    quantity.value = "1";
    void runSearch();
  },
);

let searchTimer: ReturnType<typeof setTimeout> | null = null;

watch(search, () => {
  if (searchTimer !== null) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => void runSearch(), 250);
});

async function runSearch(): Promise<void> {
  isSearching.value = true;

  try {
    const params = new URLSearchParams();
    if (search.value.trim() !== "") params.set("q", search.value.trim());

    const response = await fetch(`/api/v1/cashier/catalog?${params.toString()}`, {
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    });

    items.value = response.ok ? ((await response.json())?.data ?? []) : [];
  } finally {
    isSearching.value = false;
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ t("cashier.ad_hoc_charge") }}</DialogTitle>
        <DialogDescription>{{ t("cashier.ad_hoc_search") }}</DialogDescription>
      </DialogHeader>

      <div class="flex flex-col gap-3">
        <div class="relative">
          <Search
            class="absolute left-2.5 top-2.5 size-4 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            v-model="search"
            type="search"
            class="h-9 pl-8"
            :placeholder="t('cashier.ad_hoc_search')"
            :aria-label="t('cashier.ad_hoc_search')"
          />
        </div>

        <p
          v-if="!isSearching && items.length === 0"
          class="rounded-md border border-border/70 px-3 py-4 text-center text-xs text-muted-foreground"
        >
          {{ t("cashier.ad_hoc_none") }}
        </p>

        <ul v-else class="max-h-64 overflow-y-auto rounded-md border border-border/70">
          <li v-for="item in items" :key="item.id">
            <button
              type="button"
              class="flex w-full cursor-pointer items-center justify-between gap-3 px-3 py-2 text-left transition-colors hover:bg-muted/60"
              :class="selected?.id === item.id && 'bg-primary/10'"
              :disabled="!item.isPriced"
              @click="selected = item"
            >
              <span class="min-w-0">
                <span class="block truncate text-sm font-medium">{{ item.name }}</span>
                <span class="block text-xs text-muted-foreground">{{ item.code }}</span>
              </span>
              <span
                class="shrink-0 text-sm tabular-nums"
                :class="item.isPriced ? 'font-semibold' : 'text-warning'"
              >
                {{
                  item.isPriced
                    ? formatMoney(item.unitPrice, item.currencyCode)
                    : t("cashier.not_priced")
                }}
              </span>
            </button>
          </li>
        </ul>

        <p class="text-xs text-muted-foreground">{{ t("cashier.ad_hoc_scope_hint") }}</p>

        <div class="flex flex-col gap-1.5">
          <Label for="cashier-quantity">{{ t("cashier.ad_hoc_quantity") }}</Label>
          <Input
            id="cashier-quantity"
            v-model="quantity"
            type="number"
            min="1"
            step="1"
            class="h-9 w-28 tabular-nums"
          />
        </div>
      </div>

      <DialogFooter>
        <Button variant="ghost" class="cursor-pointer" @click="emit('update:open', false)">
          {{ t("cashier.cancel") }}
        </Button>
        <Button
          class="cursor-pointer"
          :disabled="selected === null || isSubmitting"
          @click="
            selected &&
              emit('confirm', {
                chargeableItemId: selected.id,
                quantity: Math.max(1, Number(quantity) || 1),
              })
          "
        >
          {{ t("cashier.add_charge") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
