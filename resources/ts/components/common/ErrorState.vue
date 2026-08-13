/**
 * ErrorState — composite component (Volume 1.2 §4.2, §16)
 * =========================================================
 * Shown when a fetch failed or an error occurred. Anatomy (Volume 1.2 §16.1):
 *   TriangleAlert (lucide-vue-next, Volume 0.5 §2)
 *   Failed to load results   ← human-readable, not a raw error code
 *   The server returned...   ← guidance
 *   [ Retry ]  [ Contact IT ]
 *
 * Rules (§16.2):
 *   - Error message is human-readable, not a raw error code.
 *   - Every error state has a Retry action.
 *   - Critical errors (data loss risk) also have a Contact IT action.
 *   - role="alert" on the error message.
 *   - Error details (for debugging) are in a collapsible (details), not shown by default.
 *   - Errors are logged to telemetry (Volume 3.3).
 */

<script setup lang="ts">
import { TriangleAlert } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { useI18nSafe } from '@/composables/useI18nSafe';

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        /** Raw error details — shown in a collapsible <details>, not by default. */
        details?: string;
        /** Show the "Contact IT" action (critical errors, data-loss risk). */
        critical?: boolean;
        /** Show the Retry action. Defaults to true. */
        showRetry?: boolean;
    }>(),
    {
        title: undefined,
        description: undefined,
        details: undefined,
        critical: false,
        showRetry: true,
    },
);

const emit = defineEmits<{
    retry: [];
    contactIt: [];
}>();

const { t } = useI18nSafe();
</script>

<template>
    <div
        class="flex h-full flex-col items-center justify-center gap-3 px-6 py-16 text-center"
        role="alert"
    >
        <!-- Error icon -->
        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
            <TriangleAlert class="h-6 w-6" aria-hidden="true" />
        </div>

        <!-- Title (human-readable) -->
        <h3 class="text-lg font-medium text-foreground">
            {{ title ?? t('common.error_generic') }}
        </h3>

        <!-- Description -->
        <p v-if="description" class="max-w-sm text-sm text-muted-foreground">
            {{ description }}
        </p>

        <!-- Debug details — collapsible, not shown by default (§16.2) -->
        <details v-if="details" class="w-full max-w-sm text-left">
            <summary class="cursor-pointer text-xs text-muted-foreground hover:text-foreground">
                {{ t('errors.details') }}
            </summary>
            <pre class="mt-2 overflow-auto rounded-md border border-border bg-muted p-3 text-xs text-muted-foreground">{{ details }}</pre>
        </details>

        <!-- Actions -->
        <div class="mt-1 flex gap-3">
            <Button
                v-if="showRetry"
                @click="emit('retry')"
            >
                {{ t('common.retry') }}
            </Button>
            <Button
                v-if="critical"
                variant="secondary"
                @click="emit('contactIt')"
            >
                {{ t('errors.contact_it') }}
            </Button>
        </div>
    </div>
</template>