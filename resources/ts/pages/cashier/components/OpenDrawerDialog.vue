<!--
  OpenDrawerDialog — declare the float
  =====================================
  The cashier states the cash they were issued. Everything the close is
  measured against starts from this number, so it is asked for deliberately
  rather than defaulted to zero.
-->
<script setup lang="ts">
import { computed, ref, watch } from "vue";
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
import { fromAmountInput } from "../cashierFormatters";

const props = defineProps<{ open: boolean; isSubmitting: boolean }>();

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
  (e: "confirm", openingFloatMinor: number): void;
}>();

const { t } = useI18nSafe();

const floatInput = ref("0");

watch(
  () => props.open,
  (open) => {
    if (open) floatInput.value = "0";
  },
);

const floatMinor = computed(() => fromAmountInput(floatInput.value));
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-xl">
      <DialogHeader>
        <DialogTitle>{{ t("cashier.open_drawer") }}</DialogTitle>
        <DialogDescription>{{ t("cashier.opening_float_hint") }}</DialogDescription>
      </DialogHeader>

      <div class="flex flex-col gap-1.5">
        <Label for="cashier-float">{{ t("cashier.opening_float") }}</Label>
        <Input
          id="cashier-float"
          v-model="floatInput"
          type="number"
          inputmode="decimal"
          min="0"
          step="1"
          class="h-11 text-lg tabular-nums"
        />
      </div>

      <DialogFooter>
        <Button variant="ghost" class="cursor-pointer" @click="emit('update:open', false)">
          {{ t("cashier.cancel") }}
        </Button>
        <Button
          class="cursor-pointer"
          :disabled="isSubmitting"
          @click="emit('confirm', floatMinor)"
        >
          {{ t("cashier.open_drawer") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
