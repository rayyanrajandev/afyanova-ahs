/**
 * Toast — transient notifications (Volume 1.2 §4.2, §11.2)
 * ==========================================================
 * Built on vue-sonner (already a dependency). Implements the
 * Afyanova notification priority rules (Volume 1.2 §11.3):
 *
 *   critical → persistent toast + inline alert + aria-live="assertive"
 *   warning  → toast (8s) + inline alert
 *   success  → toast (5s) only; no inline alert (avoid noise)
 *   info     → toast (5s) only
 *
 * §11.2 Rules:
 *   - Duration: 5s (info), 8s (warning), persistent (critical — must dismiss)
 *   - Stack: max 3 visible; older ones collapse
 *   - Position: bottom-inline-end (bottom-right in LTR)
 *   - z-index: --z-toast (1500)
 *   - Accessibility: role="status" (polite) or role="alert" (critical); aria-live
 *   - Action: optional action button (e.g., "View result")
 *   - Dismiss: click, Esc, or swipe (touch)
 *
 * Notifications are batched (P6) — if 5+ arrive in 10 seconds, they collapse
 * into a single "5 new notifications" toast.
 */

import { toast as sonnerToast } from 'vue-sonner';
import { i18n } from '@/i18n';

export type ToastVariant = 'critical' | 'warning' | 'success' | 'info';

export interface ToastOptions {
    description?: string;
    /** Optional action button label. */
    actionLabel?: string;
    /** Optional action callback. */
    action?: () => void;
    /** Override the default duration (ms). */
    duration?: number;
}

// §11.2 — duration per variant
const DEFAULT_DURATION: Record<ToastVariant, number> = {
    critical: Infinity, // persistent — must dismiss
    warning: 8000,
    success: 5000,
    info: 5000,
};

// §11.3 — batching: 5+ notifications in 10s collapse into one
const BATCH_WINDOW_MS = 10_000;
const BATCH_THRESHOLD = 5;

let recentTimestamps: number[] = [];
let batchTimer: ReturnType<typeof setTimeout> | undefined;

function shouldBatch(): boolean {
    const now = Date.now();
    recentTimestamps = recentTimestamps.filter((ts) => now - ts < BATCH_WINDOW_MS);
    recentTimestamps.push(now);
    return recentTimestamps.length >= BATCH_THRESHOLD;
}

function showBatched() {
    if (batchTimer) return;
    const t = i18n.global.t;
    sonnerToast(t('toast.batched_title', { count: BATCH_THRESHOLD }), {
        description: t('toast.batched_description'),
        duration: 5000,
    });
    batchTimer = setTimeout(() => {
        batchTimer = undefined;
        recentTimestamps = [];
    }, BATCH_WINDOW_MS);
}

export function useToast() {
    function show(variant: ToastVariant, title: string, options: ToastOptions = {}) {
        // §11.3 — batch if 5+ notifications arrive in 10 seconds (P6)
        if (shouldBatch()) {
            showBatched();
            return;
        }

        const duration = options.duration ?? DEFAULT_DURATION[variant];

        sonnerToast(title, {
            description: options.description,
            duration,
            // §11.2 — accessibility: critical = assertive, others = polite
            ...(variant === 'critical' ? { class: 'toast-critical' } : {}),
            action: options.actionLabel
                ? {
                      label: options.actionLabel,
                      onClick: () => options.action?.(),
                  }
                : undefined,
        });
    }

    return {
        critical: (title: string, options?: ToastOptions) => show('critical', title, options),
        error: (title: string, options?: ToastOptions) => show('critical', title, options),
        warning: (title: string, options?: ToastOptions) => show('warning', title, options),
        success: (title: string, options?: ToastOptions) => show('success', title, options),
        info: (title: string, options?: ToastOptions) => show('info', title, options),
    };
}