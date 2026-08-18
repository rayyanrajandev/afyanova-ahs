/**
 * The dispensing pane must not open empty while the queue has work.
 *
 * Pharmacy had the auto-select Laboratory has, but behind a guard that read
 * `!currentExists && !selectedOrderId.value` — and an empty id can never match
 * a row, so the whole condition collapsed to "only when nothing is selected".
 * The stale-selection branch was dead. Worse, useWorkspaceUrlSync restores
 * `?order=` by assigning the id ref directly, so a deep link left the id set
 * and the detail — which is what the pane actually renders — never fetched.
 */

import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/composables/useToast", () => ({
  useToast: () => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
    critical: vi.fn(),
  }),
}));

const { usePharmacyOrders } =
  await import("@/pages/pharmacy/composables/usePharmacyOrders");

const ORDERS = [
  {
    id: "rx-1",
    patientId: "pat-1",
    patientName: "Amina Juma",
    status: "pending",
  },
  {
    id: "rx-2",
    patientId: "pat-2",
    patientName: "Baraka Moshi",
    status: "pending",
  },
];

function json(data: unknown) {
  return Promise.resolve({ ok: true, json: () => Promise.resolve({ data }) });
}

/**
 * Serves the list, the counts, and per-order detail off one fixture set.
 *
 * `hold` names order ids whose detail response is parked until the returned
 * `release` is called, so a slow answer can be made to land after a fast one.
 */
function stubFetch(list: typeof ORDERS, hold: string[] = []) {
  const parked: Array<() => void> = [];

  const spy = vi.fn((url: string) => {
    if (url.includes("/status-counts")) return json({ total: list.length });
    if (url.includes("/safety-review")) return json(null);

    const detail = url.match(/\/orders\/([^/?]+)$/);
    if (detail) {
      const body = () => json(list.find((o) => o.id === detail[1]) ?? null);

      if (hold.includes(detail[1])) {
        return new Promise((resolve) => parked.push(() => resolve(body())));
      }

      return body();
    }

    return json(list);
  });

  vi.stubGlobal("fetch", spy);

  return Object.assign(spy, {
    release: () => parked.splice(0).forEach((fn) => fn()),
  });
}

describe("pharmacy auto-selection", () => {
  let pharmacy: ReturnType<typeof usePharmacyOrders>;

  beforeEach(() => {
    vi.unstubAllGlobals();
    // State lives at module scope, so it survives between tests the way it
    // survives between mounts in the SPA. Clear it explicitly.
    pharmacy = usePharmacyOrders();
    pharmacy.selectedOrderId.value = "";
    pharmacy.selectedOrder.value = null;
    pharmacy.orders.value = [];
  });

  it("opens the first order in the queue when nothing is selected", async () => {
    stubFetch(ORDERS);

    await pharmacy.fetchOrders();

    expect(pharmacy.selectedOrderId.value).toBe("rx-1");
    expect(pharmacy.selectedOrder.value?.id).toBe("rx-1");
  });

  it("loads the detail for an id restored from the URL", async () => {
    stubFetch(ORDERS);
    // What useWorkspaceUrlSync does on mount: id assigned, detail never fetched.
    pharmacy.selectedOrderId.value = "rx-2";

    await pharmacy.fetchOrders();

    expect(pharmacy.selectedOrderId.value).toBe("rx-2");
    expect(pharmacy.selectedOrder.value?.id).toBe("rx-2");
  });

  it("falls back to the first order when the selection has left the worklist", async () => {
    stubFetch(ORDERS);
    pharmacy.selectedOrderId.value = "rx-gone";

    await pharmacy.fetchOrders();

    expect(pharmacy.selectedOrderId.value).toBe("rx-1");
    expect(pharmacy.selectedOrder.value?.id).toBe("rx-1");
  });

  it("leaves the selection alone once its detail is loaded", async () => {
    stubFetch(ORDERS);
    await pharmacy.fetchOrders();

    const spy = stubFetch(ORDERS);
    await pharmacy.fetchOrders();

    expect(pharmacy.selectedOrderId.value).toBe("rx-1");
    expect(
      spy.mock.calls.some(([url]) => String(url).endsWith("/orders/rx-1")),
    ).toBe(false);
  });

  it("clears the selection when the queue empties", async () => {
    stubFetch(ORDERS);
    await pharmacy.fetchOrders();

    stubFetch([]);
    await pharmacy.fetchOrders();

    expect(pharmacy.selectedOrderId.value).toBe("");
    expect(pharmacy.selectedOrder.value).toBeNull();
    expect(pharmacy.safetyReview.value).toBeNull();
  });
});

describe("pharmacy order switching", () => {
  let pharmacy: ReturnType<typeof usePharmacyOrders>;

  beforeEach(async () => {
    vi.unstubAllGlobals();
    pharmacy = usePharmacyOrders();
    pharmacy.selectedOrderId.value = "";
    pharmacy.selectedOrder.value = null;
    pharmacy.orders.value = [];
    pharmacy.safetyReview.value = null;

    stubFetch(ORDERS);
    await pharmacy.fetchOrders();
  });

  it("paints the new order without waiting on the network", () => {
    // Deliberately not awaited: the pane must have swapped already.
    void pharmacy.selectOrder("rx-2");

    expect(pharmacy.selectedOrder.value?.id).toBe("rx-2");
    expect(pharmacy.isLoadingDetails.value).toBe(false);
  });

  it("drops the previous order's safety review on switch", () => {
    pharmacy.safetyReview.value = { severity: "high" } as never;

    void pharmacy.selectOrder("rx-2");

    expect(pharmacy.safetyReview.value).toBeNull();
  });

  it("ignores a slow response for an order the user has left", async () => {
    const spy = stubFetch(ORDERS, ["rx-1"]);

    const slow = pharmacy.selectOrder("rx-1");
    await pharmacy.selectOrder("rx-2");

    spy.release();
    await slow;

    expect(pharmacy.selectedOrderId.value).toBe("rx-2");
    expect(pharmacy.selectedOrder.value?.id).toBe("rx-2");
  });

  it("refreshes the open order from a background poll", async () => {
    await pharmacy.selectOrder("rx-1");

    stubFetch([{ ...ORDERS[0], status: "dispensed" }, ORDERS[1]]);
    await pharmacy.fetchOrders(true);

    expect(pharmacy.selectedOrder.value?.status).toBe("dispensed");
  });
});

describe("pharmacy worklist query", () => {
  beforeEach(() => {
    vi.unstubAllGlobals();
  });

  it("asks for a full page rather than the endpoint's default of 15", async () => {
    const seen: string[] = [];
    vi.stubGlobal(
      "fetch",
      vi.fn((url: string) => {
        seen.push(String(url));
        return json([]);
      }),
    );

    const pharmacy = usePharmacyOrders();
    await pharmacy.fetchOrders();

    const worklist = seen.find(
      (u) => u.includes("/orders?") && !u.includes("status-counts"),
    );
    expect(worklist).toContain("perPage=100");
  });

  it("scopes the tab counts to the search box", async () => {
    const seen: string[] = [];
    vi.stubGlobal(
      "fetch",
      vi.fn((url: string) => {
        seen.push(String(url));
        return json([]);
      }),
    );

    const pharmacy = usePharmacyOrders();
    pharmacy.searchQuery.value = "amox";
    await pharmacy.fetchOrders();

    // Counts used to be fetched bare, so searching narrowed the list and left
    // every chip reading its unfiltered total.
    expect(seen.find((u) => u.includes("status-counts"))).toContain("q=amox");
  });
});
