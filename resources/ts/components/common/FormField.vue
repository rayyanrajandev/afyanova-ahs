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
 */

<script setup lang="ts">
import { useId } from 'vue';
import { Label } from '@/components/ui/label';

const props = withDefaults(
    defineProps<{
        label: string;
        required?: boolean;
        help?: string;
        error?: string;
        htmlFor?: string;
    }>(),
    {
        required: false,
        help: undefined,
        error: undefined,
        htmlFor: undefined,
    },
);

const generatedId = useId();
const inputId = props.htmlFor ?? generatedId;
const helpId = `${inputId}-help`;
const errorId = `${inputId}-error`;
</script>

<template>
    <div class="grid gap-1.5">
        <Label :for="inputId" :required="required">{{ label }}</Label>
        <slot :id="inputId" :aria-describedby="error ? errorId : help ? helpId : undefined" :aria-invalid="error ? 'true' : undefined" />
        <p v-if="help && !error" :id="helpId" class="text-xs text-muted-foreground">
            {{ help }}
        </p>
        <p v-if="error" :id="errorId" class="text-xs text-destructive" role="alert">
            {{ error }}
        </p>
    </div>
</template>