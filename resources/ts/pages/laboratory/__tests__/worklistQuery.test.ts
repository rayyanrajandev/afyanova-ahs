/**
 * A worklist may not invent rows, and a filter is a question for the server.
 *
 * Laboratory used to swap in a hardcoded demo dataset — six invented patients
 * with MRNs — whenever the worklist came back empty *or* the request threw. So
 * the "Done" tab on a lab with nothing completed showed six fabricated orders,
 * five of which were not done, and a 500 looked exactly like a quiet day.
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

const { useLaboratoryOrders } =
  await import("@/pages/laboratory/composables/useLaboratoryOrders");

type Lab = ReturnType<typeof useLaboratoryOrders>;

/** Records every worklist URL asked for, and answers with `rows`. */
function stubFetch(rows: unknown[] = []) {
  const seen: string[] = [];

  vi.stubGlobal(
    "fetch",
    vi.fn((url: string) => {
      seen.push(String(url));

      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ data: rows }),
      });
    }),
  );

  return {
    worklistUrl: () => seen.find((u) => u.includes("/orders?")) ?? "",
    countsUrl: () => seen.find((u) => u.includes("status-counts")) ?? "",
  };
}

describe("laboratory worklist", () => {
  let lab: Lab;

  beforeEach(() => {
    vi.unstubAllGlobals();
    lab = useLaboratoryOrders();
  });

  it("renders nothing when a filter genuinely matches nothing", async () => {
    stubFetch([]);
    lab.selectedStatusFilter.value = "completed";

    await lab.fetchOrders();

    expect(lab.orders.value).toEqual([]);
    expect(lab.loadFailed.value).toBe(false);
  });

  it("asks the server for the selected status", async () => {
    const spy = stubFetch([]);
    lab.selectedStatusFilter.value = "completed";

    await lab.fetchOrders();

    expect(spy.worklistUrl()).toContain("status=completed");
  });

  it("reports a failed load instead of painting rows over it", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(() => Promise.reject(new Error("network down"))),
    );

    await lab.fetchOrders();

    expect(lab.orders.value).toEqual([]);
    expect(lab.loadFailed.value).toBe(true);
  });

  it("clears the failure once a load succeeds", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(() => Promise.reject(new Error("network down"))),
    );
    await lab.fetchOrders();
    expect(lab.loadFailed.value).toBe(true);

    stubFetch([]);
    await lab.fetchOrders();

    expect(lab.loadFailed.value).toBe(false);
  });

  it("asks for a full page, not the endpoint's default of 15", async () => {
    const spy = stubFetch([]);

    await lab.fetchOrders();

    expect(spy.worklistUrl()).toContain("perPage=200");
  });
});
