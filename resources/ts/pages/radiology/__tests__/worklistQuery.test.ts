/**
 * The imaging worklist is a query, not a page to sift through in the browser.
 *
 * This fetched a flat `?perPage=50` and then did status, modality and search
 * filtering client-side, summing the tab counts from that same page. A
 * department with more than fifty studies lost the rest, every tab filtered a
 * truncated set, and the counts described the page rather than the department.
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

const { useRadiologyOrders } =
  await import("@/pages/radiology/composables/useRadiologyOrders");

type Radiology = ReturnType<typeof useRadiologyOrders>;

function stubFetch(counts: Record<string, number> = {}) {
  const seen: string[] = [];

  vi.stubGlobal(
    "fetch",
    vi.fn((url: string) => {
      seen.push(String(url));
      const isCounts = String(url).includes("status-counts");

      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ data: isCounts ? counts : [] }),
      });
    }),
  );

  return {
    worklistUrl: () =>
      seen.find(
        (u) => u.includes("/orders?") && !u.includes("status-counts"),
      ) ?? "",
    countsUrl: () => seen.find((u) => u.includes("status-counts")) ?? "",
  };
}

describe("radiology worklist query", () => {
  let radiology: Radiology;

  beforeEach(() => {
    vi.unstubAllGlobals();
    radiology = useRadiologyOrders();
  });

  it("asks for a full page rather than a flat fifty", async () => {
    const spy = stubFetch();

    await radiology.fetchOrders();

    expect(spy.worklistUrl()).toContain("perPage=100");
  });

  it("sends the status tab to the server", async () => {
    const spy = stubFetch();
    radiology.selectedStatusFilter.value = "completed";

    await radiology.fetchOrders();

    expect(spy.worklistUrl()).toContain("status=completed");
  });

  it("sends the search box to the server", async () => {
    const spy = stubFetch();
    radiology.searchQuery.value = "chest";

    await radiology.fetchOrders();

    expect(spy.worklistUrl()).toContain("q=chest");
  });

  it("takes its tab counts from the server, not the loaded page", async () => {
    stubFetch({ all: 217, ordered: 88, completed: 61 });

    await radiology.fetchOrders();

    // Nothing was returned in `data`, so a page-derived count would read zero.
    expect(radiology.orders.value).toEqual([]);
    expect(radiology.statusCounts.value.all).toBe(217);
    expect(radiology.statusCounts.value.ordered).toBe(88);
  });

  it("narrows the counts by search, but never by status", async () => {
    const spy = stubFetch();
    radiology.selectedStatusFilter.value = "completed";
    radiology.searchQuery.value = "chest";

    await radiology.fetchOrders();

    const counts = spy.countsUrl();
    expect(counts).toContain("q=chest");
    // The counts *are* the breakdown by status; scoping them to one would
    // zero out every other tab.
    expect(counts).not.toContain("status=");
  });
});
