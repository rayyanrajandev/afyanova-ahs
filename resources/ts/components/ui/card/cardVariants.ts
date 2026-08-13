/**
 * cardVariants — CVA helper (Volume 1.2 §5.2)
 * ==============================================
 * Card.vue previously had no `variant` prop at all, despite Volume 1.2 §5.2
 * documenting six (default/elevated/critical/warning/success/muted) — every
 * call site that needed a critical/muted look hand-inlined the classes
 * instead (Volume 3.7 audit, 2026-08-10). This is the single source of
 * truth those call sites should use instead, matching the
 * buttonVariants/badgeVariants CVA pattern already established.
 *
 * Shadow removed from default/critical/warning/success (2026-08-10,
 * component-library audit) — Volume 0.2 §7.4 is explicit: "borders over
 * shadows... shadows are reserved for floating layers." A Card sitting in
 * the content region isn't a floating layer, so it never earned one in
 * the first place — this file's own earlier draft (same audit pass) had
 * carried an un-earned `shadow-elevation-sm` on every non-muted variant.
 * critical/warning/success already signal via a 2px colored border, making
 * a shadow on top of that doubly redundant, not just unearned.
 * `elevated` keeps `shadow-elevation-md` as the one deliberate exception:
 * its entire name and purpose is "make this stand out," and it's already
 * differentiated from default by a distinct `bg-surface-raised` token, not
 * relying on the shadow alone.
 */

import { cva, type VariantProps } from 'class-variance-authority';

export const cardVariants = cva(
    'flex flex-col gap-6 rounded-md text-foreground py-[var(--space-control-md)]',
    {
        variants: {
            variant: {
                default: 'border border-border bg-surface',
                elevated: 'border border-border bg-surface-raised shadow-elevation-md',
                // 2px border, default (surface) bg — §5.2's own definition;
                // color signals severity, but never replaces the section's
                // own label/badge (Volume 0.3 §3 "never color alone").
                critical: 'border-2 border-critical bg-surface',
                warning: 'border-2 border-warning bg-surface',
                success: 'border-2 border-success bg-surface',
                muted: 'border-none bg-muted',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

export type CardVariants = VariantProps<typeof cardVariants>;
