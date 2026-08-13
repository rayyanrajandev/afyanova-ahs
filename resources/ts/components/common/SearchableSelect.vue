<!--
  SearchableSelect — searchable, keyboard-native single-select (Volume 0.2
  §7, 2026-08-12)
  ==========================================================================
  Built for the Region/District redesign (Patient Registration UX
  direction §2 — "select, don't type, unless typing is genuinely faster").

  Composed from `npx shadcn-vue@latest add combobox` — the official CLI
  recipe (`components/ui/combobox/*`), not hand-rolled from raw reka-ui
  primitives the way the first pass at this was. That first pass had a
  real, live-confirmed bug (options rendering at the bottom of the page,
  detached from their trigger) that this rebuild on the tested official
  base doesn't reproduce — matches the same "CLI-generate then patch to
  Afyanova tokens" process this whole design system already documents on
  itself (see Input.vue, SelectTrigger.vue's own docblocks).

  One deviation from the raw installed files: `ComboboxInput.vue` imported
  its search icon from `@lucide/vue` (a second icon package the CLI pulled
  in on install) instead of `lucide-vue-next`, the one icon package every
  other component in this app already uses — fixed there, and
  `@lucide/vue` removed from package.json, rather than carrying two icon
  libraries for one icon.

  Same external API as the version this replaces (`modelValue`, `options`,
  `emptyText`, `disabledText`) — PatientRegistrationFields.vue's actual
  usage doesn't change, only what's underneath it.
-->

<script setup lang="ts">
import { Check, ChevronsUpDown } from "lucide-vue-next";
import { computed } from "vue";
import {
  Combobox,
  ComboboxAnchor,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxItemIndicator,
  ComboboxList,
  ComboboxTrigger,
  ComboboxViewport,
} from "@/components/ui/combobox";

export interface SearchableSelectOption {
  value: string;
  label: string;
}

const props = defineProps<{
  /** Empty string, not null — matches every other plain-string form field this sits alongside (vee-validate string fields). */
  modelValue: string;
  options: SearchableSelectOption[];
  id?: string;
  placeholder?: string;
  emptyText?: string;
  disabledText?: string;
  disabled?: boolean;
  ariaDescribedby?: string;
  ariaInvalid?: boolean;
}>();

const emit = defineEmits<{
  (e: "update:modelValue", value: string): void;
  /**
   * Forwarded from ComboboxInput's own native blur (2026-08-12, bug fix —
   * see PatientRegistrationFields.vue's own docblock on `handleBlur`).
   * `blur` doesn't bubble, so a consumer's `@blur` on `<SearchableSelect>`
   * would silently never fire without this explicit re-emit — the actual
   * focusable input is nested inside Combobox > ComboboxAnchor, not this
   * component's own root.
   */
  (e: "blur", value: FocusEvent): void;
}>();

/**
 * Case-insensitive match with a fallback to the raw stored value (bug
 * found 2026-08-12 live-testing Edit Demographics on a real pre-existing
 * patient): region/district were free text before this control existed,
 * so a saved record can carry a casing that doesn't exactly match this
 * list's canonical spelling — "Dar es salaam" vs. "Dar es Salaam" — and
 * an exact-only match would render the field blank for a patient who
 * very much has a real, already-saved value. Falling back to the raw
 * value (never blank) means this control can never make it look like
 * existing data silently disappeared.
 */
const selectedLabel = computed(() => {
  if (!props.modelValue) return "";
  const normalized = props.modelValue.trim().toLowerCase();
  const exact = props.options.find((option) => option.value === props.modelValue);
  if (exact) return exact.label;
  const caseInsensitive = props.options.find(
    (option) => option.value.trim().toLowerCase() === normalized,
  );
  return caseInsensitive?.label ?? props.modelValue;
});

/**
 * Forces ComboboxInput to remount the moment `options` goes from empty to
 * populated (bug found 2026-08-12, same live test as above): reka-ui's
 * ComboboxInput only recomputes `display-value` from a
 * `watch(rootContext.modelValue, ...)` — see node_modules/reka-ui/dist/
 * Combobox/ComboboxInput.js's own `resetSearchTerm`. It never watches the
 * options list, so a field whose value is already set when this component
 * mounts (e.g. Region on Edit Demographics) resolves `selectedLabel`
 * against an empty array — before useLocationOptions.ts's fetch has
 * returned — and then never re-resolves once the real list lands, because
 * the field's own value never changes. A patient with legacy-cased data
 * ends up permanently showing "Dar es salaam" instead of self-healing to
 * "Dar es Salaam" the instant the canonical list is available. Remounting
 * re-runs reka-ui's own immediate watch against the now-populated list.
 * `options.length > 0` only flips once per field in practice (this is
 * static reference data — useLocationOptions.ts loads it exactly once per
 * session), so this isn't a remount-on-every-render footgun.
 */
const optionsReady = computed(() => (props.options.length > 0 ? "loaded" : "loading"));

function onUpdate(value: unknown) {
  emit("update:modelValue", typeof value === "string" ? value : "");
}
</script>

<template>
  <Combobox
    :model-value="modelValue || null"
    :disabled="disabled"
    open-on-focus
    @update:model-value="onUpdate"
  >
    <ComboboxAnchor class="w-full">
      <div
        :class="[
          'border-input bg-surface flex h-[var(--size-control-md)] w-full items-center rounded-md border pr-2 shadow-xs transition-[color,box-shadow,border-color]',
          'hover:border-ring/50',
          'has-[input:focus-visible]:border-ring has-[input:focus-visible]:ring-ring has-[input:focus-visible]:ring-2 has-[input:focus-visible]:ring-offset-1 has-[input:focus-visible]:ring-offset-background',
          disabled && 'pointer-events-none opacity-50',
        ]"
      >
        <ComboboxInput
          :key="optionsReady"
          :id="id"
          :display-value="() => selectedLabel"
          :placeholder="disabled ? disabledText : placeholder"
          :aria-describedby="ariaDescribedby"
          :aria-invalid="ariaInvalid"
          class="h-full flex-1 border-0 p-0 px-3 text-sm text-foreground shadow-none placeholder:text-muted-foreground"
          @blur="emit('blur', $event)"
        />
        <ComboboxTrigger :disabled="disabled" class="flex shrink-0 items-center">
          <ChevronsUpDown class="h-4 w-4 opacity-50" aria-hidden="true" />
        </ComboboxTrigger>
      </div>
    </ComboboxAnchor>

    <ComboboxList class="w-(--reka-combobox-trigger-width)">
      <ComboboxEmpty>{{ emptyText }}</ComboboxEmpty>
      <ComboboxViewport>
        <ComboboxItem
          v-for="option in options"
          :key="option.value"
          :value="option.value"
          :text-value="option.label"
        >
          {{ option.label }}
          <ComboboxItemIndicator>
            <Check class="size-4" aria-hidden="true" />
          </ComboboxItemIndicator>
        </ComboboxItem>
      </ComboboxViewport>
    </ComboboxList>
  </Combobox>
</template>
