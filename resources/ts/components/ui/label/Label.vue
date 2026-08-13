/**
 * Label — shadcn-vue component (Volume 1.2 §4.1, §7.2)
 * =====================================================
 * Official CLI-generated shadcn-vue structure, patched to Afyanova tokens:
 *   - `text-foreground` (Afyanova token) alongside the default sizing
 *
 * `required` renders a small "Required" text tag, not a red asterisk
 * (design direction 2026-08-12, researched against current guidance —
 * Nielsen Norman Group's own required-fields writeup and Baymard
 * Institute usability data: marking *only* optional fields and leaving
 * required ones bare measurably increases required-field omission
 * errors, so required fields still need marking on a form like this one
 * where the split isn't overwhelmingly one-sided — the fix is a quieter
 * *visual treatment*, not dropping the marking). A red asterisk repeated
 * on most fields in a dense clinical form reads as alarm/error styling
 * everywhere at once; a small muted-foreground text tag communicates the
 * same fact without borrowing the destructive color used for actual
 * validation errors elsewhere in this same form.
 *
 * Deliberately NOT uppercase/tracking-wide (2026-08-12 follow-up, direct
 * user feedback on the first pass — "Required text is too visually
 * dominant... it should communicate status without competing with the
 * label"): all-caps + letter-spacing is a strong typographic treatment
 * on its own regardless of size/color, the same tool this component's
 * section headings use specifically to stand out. Using it on an inline
 * per-field annotation made "REQUIRED" fight the label next to it for
 * attention — plain sentence case at reduced opacity reads as quieter
 * status text instead. Visible (not aria-hidden) — the text itself is
 * the required-field disclosure, not decoration, so it should be
 * announced like any other label content rather than relying solely on
 * each consumer also setting the native `required` HTML attribute on
 * its own input.
 */

<script setup lang="ts">
import { reactiveOmit } from '@vueuse/core';
import type { LabelProps } from 'reka-ui';
import { Label } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { useI18n } from 'vue-i18n';
import { cn } from '@/lib/utils';

const props = withDefaults(defineProps<LabelProps & { class?: HTMLAttributes['class']; required?: boolean }>(), {
    required: false,
});

const { t } = useI18n();
const delegatedProps = reactiveOmit(props, 'class', 'required');
</script>

<template>
    <Label
        data-slot="label"
        v-bind="delegatedProps"
        :class="
            cn(
                'flex items-center gap-1.5 text-sm leading-none font-medium text-foreground select-none group-data-[disabled=true]:pointer-events-none group-data-[disabled=true]:opacity-50 peer-disabled:cursor-not-allowed peer-disabled:opacity-50',
                props.class,
            )
        "
    >
        <slot />
        <span v-if="required" class="text-xs font-normal text-muted-foreground/70">
            {{ t('common.required') }}
        </span>
    </Label>
</template>