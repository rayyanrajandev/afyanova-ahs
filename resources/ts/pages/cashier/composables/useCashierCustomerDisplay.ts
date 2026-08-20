/**
 * useCashierCustomerDisplay — Secondary Screen / CFD Bridge
 * ==========================================================
 * Synchronizes the cashier counter with a secondary customer-facing display
 * monitor or tablet in real time using the HTML5 BroadcastChannel API.
 */

import { onMounted, onUnmounted, ref } from "vue";

export interface CustomerDisplayPayload {
  state: "idle" | "basket_active" | "payment_prompt" | "payment_success";
  facilityName?: string;
  patientName?: string | null;
  patientNumber?: string | null;
  charges?: Array<{ description: string; amount: string; quantity: number }>;
  totalDue?: string;
  currencyCode?: string;
  qrPayload?: string;
  receiptNumber?: string;
}

const CHANNEL_NAME = "afyanova_cashier_customer_display";

export function useCashierCustomerDisplaySender() {
  let channel: BroadcastChannel | null = null;

  onMounted(() => {
    if (typeof window !== "undefined" && "BroadcastChannel" in window) {
      channel = new BroadcastChannel(CHANNEL_NAME);
    }
  });

  onUnmounted(() => {
    channel?.close();
    channel = null;
  });

  function broadcast(payload: CustomerDisplayPayload): void {
    try {
      channel?.postMessage(payload);
    } catch {
      // Graceful ignore if unsupported
    }
  }

  return { broadcast };
}

export function useCashierCustomerDisplayReceiver() {
  const display = ref<CustomerDisplayPayload>({ state: "idle" });
  let channel: BroadcastChannel | null = null;

  onMounted(() => {
    if (typeof window !== "undefined" && "BroadcastChannel" in window) {
      channel = new BroadcastChannel(CHANNEL_NAME);
      channel.onmessage = (event: MessageEvent<CustomerDisplayPayload>) => {
        if (event.data && event.data.state) {
          display.value = event.data;
        }
      };
    }
  });

  onUnmounted(() => {
    channel?.close();
    channel = null;
  });

  return { display };
}
