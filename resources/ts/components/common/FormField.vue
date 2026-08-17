/**
 * FormField — composite component (Volume 1.2 §4.2, §7.2)
 * =========================================================
 * Label + input + help text + error message composite.
 * Anatomy (Volume 1.2 §7.2):
 *   Label *            ← --text-sm, --font-weight-medium, * = required
 *   [ Input ........ ] ← --color-input-background, --radius-sm
 *   Help text          ← --text-xs, --color-muted-foreground
 *   Error message      ← --text-xs, --color-destructive, role="alert"
 *
 * Errors are linked to the input via aria-describedby (Volume 0.3 §7.2).
 * Automatically resolves and translates validation error keys (e.g. invalid_phone, required).
 */

<script setup lang="ts">
import { computed, useId } from 'vue';
import { Label } from '@/components/ui/label';
import { useI18nSafe } from '@/composables/useI18nSafe';

const props = withDefaults(
    defineProps<{
        label?: string;
        required?: boolean;
        help?: string;
        error?: string;
        htmlFor?: string;
    }>(),
    {
        label: undefined,
        required: false,
        help: undefined,
        error: undefined,
        htmlFor: undefined,
    },
);

const { t, te } = useI18nSafe();

const displayError = computed(() => {
    if (!props.error) return undefined;
    const err = props.error.trim();
    const snakeCaseErr = err.toLowerCase().replace(/\s+/g, '_');
    if (te(`validation.${err}`)) return t(`validation.${err}`);
    if (te(`validation.${snakeCaseErr}`)) return t(`validation.${snakeCaseErr}`);
    if (te(`patient.${err}`)) return t(`patient.${err}`);
    if (te(`patient.${snakeCaseErr}`)) return t(`patient.${snakeCaseErr}`);
    if (te(err)) return t(err);
    return err;
});

const generatedId = useId();
const inputId = props.htmlFor ?? generatedId;
const helpId = `${inputId}-help`;
const errorId = `${inputId}-error`;
</script>

<template>
    <div class="grid gap-1.5">
        <Label v-if="label" :for="inputId" :required="required">{{ label }}</Label>
        <slot :id="inputId" :aria-describedby="displayError ? errorId : help ? helpId : undefined" :aria-invalid="displayError ? 'true' : undefined" />
        <p v-if="help && !displayError" :id="helpId" class="text-xs text-muted-foreground">
            {{ help }}
        </p>
        <p v-if="displayError" :id="errorId" class="text-xs text-destructive" role="alert">
            {{ displayError }}
        </p>
    </div>
</template>