/**
 * badgeVariants — shadcn-vue CVA helper (Volume 1.2 §4.1, §12)
 * ==============================================================
 * Official CLI-generated shadcn-vue Badge, patched to Afyanova tokens:
 *   - `ring-2` (Volume 1.6 §6) instead of the CLI's `ring-3`
 *   - `text-destructive-foreground` (token) instead of hardcoded `text-white`
 *   - Clinical variants `critical`/`warning`/`success`/`info` (Volume 1.2 §12)
 *     mapping to Afyanova status tokens — never color alone (Volume 0.3 §3).
 */

import type { VariantProps } from 'class-variance-authority';
import { cva } from 'class-variance-authority';

export { default as Badge } from './Badge.vue';

export const badgeVariants = cva(
    'inline-flex items-center justify-center rounded-full border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-2 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary/90',
                secondary: 'border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/90',
                destructive:
                    'border-transparent bg-destructive text-destructive-foreground [a&]:hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 dark:bg-destructive/60',
                outline: 'text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground',
                // ---- Clinical variants (Volume 1.2 §12) — token-driven, never color alone ----
                // Filled: critical, success, complete (bg + text)
                critical: 'bg-critical/12 text-critical border-critical/22',
                success: 'bg-success/12 text-success border-success/22',
                complete: 'bg-success/12 text-success border-success/22',
                // Outlined: warning, info, pending, in_progress, cancelled (border + text, no bg)
                warning: 'text-warning border-warning/22',
                info: 'text-info border-info/22',
                pending: 'text-warning border-warning/22',
                in_progress: 'text-info border-info/22',
                cancelled: 'text-muted-foreground border-muted/22',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);
export type BadgeVariants = VariantProps<typeof badgeVariants>;
