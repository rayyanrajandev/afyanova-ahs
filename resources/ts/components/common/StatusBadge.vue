/**
 * StatusBadge — clinical status indicator (Volume 1.2 §12)
 * ==========================================================
 * Composite component built on the shadcn-vue Badge primitive.
 * Enforces the "never color alone" rule (Volume 0.3 §3):
 * every status renders color + icon + label.
 *
 * §12.1 Status table (icons are lucide-vue-next, Volume 0.5 §2):
 *   critical     → --color-critical,     TriangleAlert, filled
 *   warning      → --color-warning,      TriangleAlert, outlined
 *   success      → --color-success,      CircleCheck,   filled
 *   info         → --color-info,         Info,          outlined
 *   pending      → --color-warning,      Clock,         outlined
 *   in_progress  → --color-info,         RotateCw,      outlined
 *   complete     → --color-success,      CircleCheck,   filled
 *   cancelled    → --color-muted-fg,     X,             outlined
 */

<script setup lang="ts">
import {
    CircleCheck,
    Clock,
    Info,
    RotateCw,
    TriangleAlert,
    X,
} from 'lucide-vue-next';
import { computed, type Component } from 'vue';
import { Badge } from '@/components/ui/badge';
import { useI18nSafe } from '@/composables/useI18nSafe';

export type StatusType =
    | 'critical'
    | 'warning'
    | 'success'
    | 'info'
    | 'pending'
    | 'in_progress'
    | 'complete'
    | 'cancelled';

const props = withDefaults(
    defineProps<{
        status: StatusType;
        label?: string;
    }>(),
    {
        status: 'info',
        label: undefined,
    },
);

const { t } = useI18nSafe();

// §12.1 — icon + label key per status
const statusConfig: Record<StatusType, { icon: Component; labelKey: string }> = {
    critical: { icon: TriangleAlert, labelKey: 'status.critical' },
    warning: { icon: TriangleAlert, labelKey: 'status.warning' },
    success: { icon: CircleCheck, labelKey: 'status.success' },
    info: { icon: Info, labelKey: 'status.info' },
    pending: { icon: Clock, labelKey: 'status.pending' },
    in_progress: { icon: RotateCw, labelKey: 'status.in_progress' },
    complete: { icon: CircleCheck, labelKey: 'status.complete' },
    cancelled: { icon: X, labelKey: 'status.cancelled' },
};

const config = computed(() => statusConfig[props.status] ?? statusConfig.info);
const displayLabel = computed(() => props.label || t(config.value.labelKey));
</script>

<template>
    <Badge
        :variant="status"
        role="img"
        :aria-label="displayLabel"
    >
        <component :is="config.icon" class="h-3 w-3 shrink-0" aria-hidden="true" />
        {{ displayLabel }}
    </Badge>
</template>
