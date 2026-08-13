/**
 * buttonVariants — shadcn-vue CVA helper (Volume 3.6 §2)
 * ========================================================
 * Single source of truth for Button variant/size classes. Both the
 * `<Button>` component and composite components (AlertDialog action/cancel,
 * Dialog footer actions) consume this, so styling never drifts.
 *
 * Tokens: density-aware `--size-control-*` (Volume 0.2 §7.1), critical
 * variant reuses `.btn-critical` (globals.css §4, Volume 1.2 §4.1).
 *
 * `active:scale-95` added 2026-08-10 (component-library audit) — across
 * the entire codebase (`ui/` and `common/`), there was exactly one
 * `active:` class anywhere, and it was a cursor-style change during a
 * drag, not visual feedback. No button, card, or interactive row gave any
 * press-down cue at all. This is the single highest-leverage fix for that:
 * every consumer of buttonVariants (Button, AlertDialog actions, Dialog
 * footer actions) picks it up at once, matching the subtle press
 * micro-interaction standard in Linear/Vercel/Stripe's dashboards.
 * `transition-colors` widened to include `transform` so the scale
 * actually animates instead of snapping.
 */

import { cva, type VariantProps } from 'class-variance-authority';

export const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-[color,background-color,border-color,transform] active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:bg-primary/90',
                destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
                critical: 'btn-critical',
                outline: 'border border-border bg-surface hover:bg-accent hover:text-accent-foreground',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
                ghost: 'hover:bg-accent hover:text-accent-foreground',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-[var(--size-control-md)] px-4 py-2',
                sm: 'h-[var(--size-control-sm)] rounded-md px-3 text-xs',
                lg: 'h-[var(--size-control-lg)] rounded-md px-8',
                icon: 'h-[var(--size-control-md)] w-[var(--size-control-md)]',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

export type ButtonVariants = VariantProps<typeof buttonVariants>;