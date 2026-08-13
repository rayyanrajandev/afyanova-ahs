/**
 * Button — shadcn-vue component (Volume 3.6 §2, ADR-0001)
 * ========================================================
 * Headless behavior from Radix Vue (reka-ui), styled with Afyanova tokens.
 *
 * `critical` variant (Volume 1.2 §4.1): destructive-with-confirmation. This
 * component only supplies the visual treatment (reuses `.btn-critical` from
 * globals.css, the single source of truth for that color pairing) — pairing
 * it with an actual confirmation flow is the caller's responsibility, e.g.
 * wrapping it in `AlertDialog` (Volume 1.2 §10.3) rather than firing the
 * destructive action directly on click.
 *
 * Variant/size classes come from `buttonVariants` (CVA) — the single source
 * of truth shared with composites (AlertDialog action/cancel).
 */

<script setup lang="ts">
import { Primitive, type PrimitiveProps } from 'reka-ui';
import { type ButtonHTMLAttributes, computed } from 'vue';
import { cn } from '@/lib/utils';
import { buttonVariants, type ButtonVariants } from './buttonVariants';

type Props = PrimitiveProps & {
    variant?: NonNullable<ButtonVariants['variant']>;
    size?: NonNullable<ButtonVariants['size']>;
} & {
    // Only `class` is declared so it merges via cn(); every other native
    // attribute (type, disabled, id, aria-*, ...) falls through to the
    // rendered element via $attrs — the official shadcn-vue contract.
    class?: ButtonHTMLAttributes['class'];
};

const props = withDefaults(defineProps<Props>(), {
    as: 'button',
    variant: 'default',
    size: 'default',
});

const classes = computed(() =>
    cn(
        buttonVariants({ variant: props.variant, size: props.size }),
        props.class,
    ),
);
</script>

<template>
    <Primitive :as="as" :as-child="asChild" :class="classes">
        <slot />
    </Primitive>
</template>