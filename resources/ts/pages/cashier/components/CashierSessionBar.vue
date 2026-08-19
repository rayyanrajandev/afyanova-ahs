<!--
  CashierSessionBar — the drawer, always in view
  ==============================================
  Pinned above both panes because everything else on this screen depends on it:
  no open drawer means no payment can be taken, and the counter should say so
  before the cashier discovers it by being refused.
-->
<script setup lang="ts">
import { ArrowDownUp, ChartColumn, LockKeyhole, Undo2, Wallet } from "lucide-vue-next";
import { computed } from "vue";
import { Button } from "@/components/ui/button";
import { useI18nSafe } from "@/composables/useI18nSafe";
import { formatMoney } from "../cashierFormatters";
import type { CashierSession } from "../composables/useCashierSession";

const props = defineProps<{
  session: CashierSession | null;
  isLoading: boolean;
  canReviewRefunds?: boolean;
  pendingRefundCount?: number;
}>();

const emit = defineEmits<{
  (e: "open"): void;
  (e: "close"): void;
  (e: "move-cash"): void;
  (e: "day-summary"): void;
  (e: "refunds"): void;
}>();

const { t } = useI18nSafe();

const isOpen = computed(() => props.session?.status === "open");
</script>

<template>
  <div
    class="flex shrink-0 items-center gap-3 border-b border-border/80 bg-surface px-4 py-2"
  >
    <div class="flex items-center gap-2">
      <Wallet
        class="size-4 shrink-0"
        :class="isOpen ? 'text-success' : 'text-muted-foreground'"
        aria-hidden="true"
      />
      <span class="text-sm font-semibold">
        {{
          isOpen
            ? t("cashier.drawer_open", { number: session?.sessionNumber })
            : t("cashier.drawer_closed")
        }}
      </span>
    </div>

    <span v-if="isOpen && session" class="text-xs text-muted-foreground">
      {{ t("cashier.drawer_float", { amount: formatMoney(session.openingFloat, session.currencyCode) }) }}
    </span>

    <div class="ml-auto flex items-center gap-2">
      <Button
        v-if="canReviewRefunds"
        variant="ghost"
        size="sm"
        class="cursor-pointer"
        @click="emit('refunds')"
      >
        <Undo2 class="mr-1.5 size-3.5" aria-hidden="true" />
        {{ t("cashier.refunds") }}
        <span
          v-if="(pendingRefundCount ?? 0) > 0"
          class="ml-1.5 rounded-full bg-warning/15 px-1.5 text-xs font-bold text-warning"
        >
          {{ pendingRefundCount }}
        </span>
      </Button>

      <Button
        variant="ghost"
        size="sm"
        class="cursor-pointer"
        @click="emit('day-summary')"
      >
        <ChartColumn class="mr-1.5 size-3.5" aria-hidden="true" />
        {{ t("cashier.day_summary") }}
      </Button>

      <Button
        v-if="!isOpen"
        size="sm"
        :disabled="isLoading"
        class="cursor-pointer"
        @click="emit('open')"
      >
        {{ t("cashier.open_drawer") }}
      </Button>

      <template v-else>
        <Button
          variant="ghost"
          size="sm"
          class="cursor-pointer"
          @click="emit('move-cash')"
        >
          <ArrowDownUp class="mr-1.5 size-3.5" aria-hidden="true" />
          {{ t("cashier.drawer") }}
        </Button>
        <Button
          variant="outline"
          size="sm"
          class="cursor-pointer"
          @click="emit('close')"
        >
          <LockKeyhole class="mr-1.5 size-3.5" aria-hidden="true" />
          {{ t("cashier.close_drawer") }}
        </Button>
      </template>
    </div>
  </div>
</template>
