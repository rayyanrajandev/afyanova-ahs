/**
 * Card — shadcn-vue component (Volume 1.2 §4.1, §5)
 * ===================================================
 * Official shadcn-vue Card anatomy (data-slot, header/body/footer slots)
 * styled with Afyanova tokens. The Afyanova bridge exposes surface colors
 * via `--color-surface`/`--color-surface-foreground` (not shadcn's
 * `--color-card`), so token classes are used instead (tailwind.css §theme).
 *
 * Radius: `--radius-md`. Padding: `--space-control-md` (density-aware).
 * Variants (§5.2): default/elevated/critical/warning/success/muted — see
 * cardVariants.ts.
 */

<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import { cardVariants, type CardVariants } from './cardVariants';

// No withDefaults — cardVariants' own `defaultVariants: { variant: 'default' }`
// (cardVariants.ts) supplies the fallback when variant is undefined, matching
// Badge.vue's pattern rather than Button.vue's explicit withDefaults one.
const props = defineProps<{
    variant?: CardVariants['variant'];
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div
        data-slot="card"
        :class="cn(cardVariants({ variant }), props.class)"
    >
        <slot />
    </div>
</template>