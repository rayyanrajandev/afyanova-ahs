/**
 * useCashierSession — the drawer
 * ===============================
 * Backed by App\Modules\Revenue's cashier session endpoints. One open drawer
 * per cashier, enforced server-side; this mirrors that rather than trying to
 * manage several.
 *
 * The expected cash figure is deliberately absent while a session is open —
 * the API withholds it until a count has been submitted, and the close dialog
 * relies on that rather than on hiding a value it was handed.
 */

import { computed, ref } from "vue";
import { useI18nSafe } from "@/composables/useI18nSafe";
import { useToast } from "@/composables/useToast";

export interface CashierSession {
  id: string;
  sessionNumber: string;
  cashierUserId: number;
  status: "open" | "pending_approval" | "closed";
  currencyCode: string;
  openingFloat: string;
  openedAt: string | null;
  countedAt: string | null;
  closedAt: string | null;
  declaredCash: string | null;
  /** Null until the drawer has been counted. That is the blind count. */
  expectedCash: string | null;
  variance: string | null;
  requiresVarianceApproval: boolean;
  approvedByUserId: number | null;
  approvalNote: string | null;
}

/** How the expected cash figure was arrived at, so the cashier can check it. */
export interface CloseBreakdown {
  openingFloat: string;
  cashTaken: string;
  cashIn: string;
  cashOut: string;
  refundsPaid: string;
  reversals: string;
  paymentCount: number;
}

export type CashMovementReason =
  | "float_top_up"
  | "banking_drop"
  | "petty_cash"
  | "correction";

const JSON_HEADERS = {
  Accept: "application/json",
  "Content-Type": "application/json",
  "X-Requested-With": "XMLHttpRequest",
};

function csrfToken(): string {
  return (
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
      ?.content ?? ""
  );
}

export function useCashierSession() {
  const { t } = useI18nSafe();
  const toast = useToast();

  const session = ref<CashierSession | null>(null);
  const isLoading = ref(false);
  const isSubmitting = ref(false);
  const error = ref<string | null>(null);

  const isOpen = computed(() => session.value?.status === "open");
  const awaitsApproval = computed(
    () => session.value?.status === "pending_approval",
  );

  async function send(url: string, method: string, body?: unknown) {
    const response = await fetch(url, {
      method,
      headers: { ...JSON_HEADERS, "X-CSRF-TOKEN": csrfToken() },
      credentials: "same-origin",
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
      // The API names its refusals so the workspace can offer the right next
      // step instead of a dead end.
      throw Object.assign(
        new Error(payload?.message ?? t("cashier.error_generic")),
        { code: payload?.code ?? null, payload },
      );
    }

    return payload;
  }

  async function fetchCurrent(): Promise<void> {
    isLoading.value = true;
    error.value = null;

    try {
      const payload = await send("/api/v1/cashier/session/current", "GET");
      session.value = payload?.data ?? null;
    } catch (e) {
      error.value = (e as Error).message;
    } finally {
      isLoading.value = false;
    }
  }

  async function open(openingFloatMinor: number): Promise<boolean> {
    isSubmitting.value = true;

    try {
      const payload = await send("/api/v1/cashier/sessions", "POST", {
        openingFloatMinor,
      });
      session.value = payload?.data ?? null;
      toast.success(t("cashier.drawer_opened"));

      return true;
    } catch (e) {
      toast.error((e as Error).message);

      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  async function recordMovement(
    reason: CashMovementReason,
    amountMinor: number,
    note?: string,
  ): Promise<boolean> {
    if (!session.value) return false;

    isSubmitting.value = true;

    try {
      await send(
        `/api/v1/cashier/sessions/${session.value.id}/movements`,
        "POST",
        { reason, amountMinor, note: note ?? null },
      );
      toast.success(t("cashier.movement_recorded"));

      return true;
    } catch (e) {
      toast.error((e as Error).message);

      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  /**
   * Submit the count. Only after this does the response carry what the ledger
   * expected, and whether a supervisor has to look.
   */
  async function close(
    declaredCashMinor: number,
  ): Promise<{
    session: CashierSession;
    requiresApproval: boolean;
    breakdown: CloseBreakdown | null;
  } | null> {
    if (!session.value) return null;

    isSubmitting.value = true;

    try {
      const payload = await send(
        `/api/v1/cashier/sessions/${session.value.id}/close`,
        "POST",
        { declaredCashMinor },
      );

      const closed = payload?.data as CashierSession;
      session.value = closed.status === "open" ? closed : null;

      return {
        session: closed,
        requiresApproval: Boolean(payload?.meta?.requiresApproval),
        breakdown: (payload?.meta?.breakdown as CloseBreakdown | undefined) ?? null,
      };
    } catch (e) {
      toast.error((e as Error).message);

      return null;
    } finally {
      isSubmitting.value = false;
    }
  }

  return {
    session,
    isOpen,
    awaitsApproval,
    isLoading,
    isSubmitting,
    error,
    fetchCurrent,
    open,
    recordMovement,
    close,
  };
}
