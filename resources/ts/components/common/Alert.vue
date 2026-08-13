/**
 * Alert — composite component (Volume 1.2 §4.2, §11.1)
 * ======================================================
 * Inline alerts appear within the content flow, not as overlays.
 *
 * §11.1 Variants (icons are lucide-vue-next, Volume 0.5 §2):
 *   critical → --color-critical, TriangleAlert, role="alert"
 *   warning  → --color-warning,  TriangleAlert, role="status"
 *   success  → --color-success,  CircleCheck,   role="status"
 *   info     → --color-info,     Info,          role="status"
 *
 * Each alert has: icon + title + description + optional action.
 * Never color alone — always icon + label (Volume 0.3 §3).
 */

<script setup lang="ts">
import { CircleCheck, Info, TriangleAlert, X } from 'lucide-vue-next';
import { computed, type Component } from 'vue';
import { useI18nSafe } from '@/composables/useI18nSafe';

export type AlertVariant = 'critical' | 'warning' | 'success' | 'info';

const props = withDefaults(
    defineProps<{
        variant?: AlertVariant;
        title?: string;
        description?: string;
        /** Optional action label — renders an action button. */
        actionLabel?: string;
        /** Dismissible — shows an X button. */
        dismissible?: boolean;
    }>(),
    {
        variant: 'info',
        title: undefined,
        description: undefined,
        actionLabel: undefined,
        dismissible: false,
    },
);

const emit = defineEmits<{
    action: [];
    dismiss: [];
}>();

const { t } = useI18nSafe();

// §11.1 — icon + role per variant
const variantConfig: Record<AlertVariant, { icon: Component; role: 'alert' | 'status' }> = {
    critical: { icon: TriangleAlert, role: 'alert' },
    warning: { icon: TriangleAlert, role: 'status' },
    success: { icon: CircleCheck, role: 'status' },
    info: { icon: Info, role: 'status' },
};

const config = computed(() => variantConfig[props.variant]);

// Token-driven classes (Volume 0.2 §14 clinical semantic tokens)
const variantClasses: Record<AlertVariant, string> = {
    critical: 'border-critical/25 bg-critical/5 text-critical',
    warning: 'border-warning/25 bg-warning/5 text-warning',
    success: 'border-success/25 bg-success/5 text-success',
    info: 'border-info/25 bg-info/5 text-info',
};
</script>

<template>
    <div
        class="flex items-start gap-3 rounded-lg border p-4 text-sm"
        :class="variantClasses[variant]"
        :role="config.role"
    >
        <!-- Icon (never color alone — Volume 0.3 §3) -->
        <component :is="config.icon" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />

        <div class="min-w-0 flex-1">
            <p v-if="title" class="font-medium text-foreground">{{ title }}</p>
            <p v-if="description" class="mt-0.5 text-muted-foreground">{{ description }}</p>
            <slot />
        </div>

        <!-- Optional action -->
        <button
            v-if="actionLabel"
            type="button"
            class="focus-ring shrink-0 rounded-md px-2 py-1 text-xs font-medium underline-offset-2 hover:underline"
            @click="emit('action')"
        >
            {{ actionLabel }}
        </button>

        <!-- Dismiss -->
        <button
            v-if="dismissible"
            type="button"
            class="focus-ring shrink-0 rounded-md p-1 text-muted-foreground hover:text-foreground"
            :aria-label="t('common.close')"
            @click="emit('dismiss')"
        >
            <X class="h-4 w-4" aria-hidden="true" />
        </button>
    </div>
</template>
