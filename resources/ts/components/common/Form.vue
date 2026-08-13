/**
 * Form — composite component (Volume 1.2 §4.2, §7.1)
 * =====================================================
 * Schema-driven form using VeeValidate + Zod. The schema defines fields,
 * validation, and layout; this component renders the FormField composites.
 *
 * Validation rules (Volume 1.2 §7.3):
 *   - Schema-first: Zod defines validation, the form derives rules.
 *   - On blur: validation runs on field blur (vee-validate default).
 *   - On submit: full validation; first error field gets focus.
 *   - Errors are role="alert" and linked via aria-describedby (FormField).
 */

<script setup lang="ts">
import { toTypedSchema } from '@vee-validate/zod';
import { Form as VeeForm, type GenericObject } from 'vee-validate';
import type { ZodTypeAny } from 'zod';

defineProps<{
    /** Zod schema — the single source of truth for field validation. */
    schema: ZodTypeAny;
    /** Optional initial form values. */
    initialValues?: GenericObject;
}>();

const emit = defineEmits<{
    /** Fired with validated values — safe to write to the API. */
    submit: [values: GenericObject];
    /** Fired when submit was attempted but validation failed. */
    invalid: [];
}>();
</script>

<template>
    <VeeForm
        :validation-schema="toTypedSchema(schema)"
        :initial-values="initialValues"
        @submit="emit('submit', $event)"
        @invalid-submit="emit('invalid')"
    >
        <slot />
    </VeeForm>
</template>