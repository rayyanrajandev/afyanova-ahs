/**
 * Orders Store (Volume 1.4 §3.1, Volume 2.2 §13.1)
 * ================================================
 * Manages lab/imaging/medication/referral orders.
 * Used by the Clinician workspace (Volume 2.2).
 *
 * API endpoints (Volume 2.2 §13.2):
 *   POST /clinician/orders/lab          — place lab order
 *   POST /clinician/orders/imaging      — place imaging order
 *   POST /clinician/orders/medication   — prescribe medication
 *   POST /clinician/orders/referral     — create referral
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

export type OrderType = 'lab' | 'imaging' | 'medication' | 'referral';
export type OrderStatus = 'pending' | 'in_progress' | 'complete' | 'cancelled';
export type OrderPriority = 'routine' | 'urgent' | 'stat';

export interface Order {
    id: string;
    patientId: string;
    type: OrderType;
    name: string;
    status: OrderStatus;
    priority?: OrderPriority;
    date: string;
}

export interface CreateOrderPayload {
    patientId: string;
    name: string;
    priority?: OrderPriority;
    [key: string]: unknown;
}

export const useOrdersStore = defineStore('orders', () => {
    // ---- State ----
    const orders = ref<Order[]>([]);
    const isLoading = ref(false);
    const error = ref<string | null>(null);

    // ---- Actions ----

    /** POST /clinician/orders/{type} */
    async function createOrder(type: OrderType, payload: CreateOrderPayload): Promise<Order | null> {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch(`/clinician/orders/${type}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload),
            });
            if (!res.ok) throw new Error(`Failed to place ${type} order`);
            const order = (await res.json()) as Order;
            orders.value.unshift(order);
            return order;
        } catch (e) {
            error.value = e instanceof Error ? e.message : `Failed to place ${type} order`;
            return null;
        } finally {
            isLoading.value = false;
        }
    }

    /** Convenience wrappers per endpoint */
    function createLabOrder(payload: CreateOrderPayload) {
        return createOrder('lab', payload);
    }

    function createImagingOrder(payload: CreateOrderPayload) {
        return createOrder('imaging', payload);
    }

    function createMedicationOrder(payload: CreateOrderPayload & { dose?: string; route?: string; frequency?: string }) {
        return createOrder('medication', payload);
    }

    function createReferralOrder(payload: CreateOrderPayload & { reason?: string }) {
        return createOrder('referral', payload);
    }

    /** Seed from results/other sources */
    function setOrders(list: Order[]) {
        orders.value = list;
    }

    return {
        orders,
        isLoading,
        error,
        createOrder,
        createLabOrder,
        createImagingOrder,
        createMedicationOrder,
        createReferralOrder,
        setOrders,
    };
});