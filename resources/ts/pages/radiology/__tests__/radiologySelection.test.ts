/**
 * The reading room opens on work, not on an empty pane.
 *
 * Radiology auto-selected only a study with an *open* status. Now that the
 * status tabs filter server-side, choosing Reported or Cancelled returns a full
 * worklist of finished studies, none of which matched — so the workstation sat
 * blank over a queue with rows in it. Laboratory and pharmacy both fall back to
 * the first row.
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

function study(overrides: Record<string, unknown> = {}) {
  return {
    id: "rad-1",
    patientId: "pat-1",
    patientName: "Amina Juma",
    modality: "ct",
    studyDescription: "CT Head Without Contrast",
    priority: "routine",
    status: "ordered",
    orderedAt: "2026-08-18T08:00:00Z",
    ...overrides,
  };
}

function stubFetch(rows: unknown[]) {
  vi.stubGlobal(
    "fetch",
    vi.fn((url: string) =>
      Promise.resolve({
        ok: true,
        json: () =>
          Promise.resolve({
            data: String(url).includes("status-counts") ? {} : rows,
          }),
      }),
    ),
  );
}

describe("radiology auto-selection", () => {
  let radiology: ReturnType<typeof useRadiologyOrders>;

  beforeEach(() => {
    vi.unstubAllGlobals();
    radiology = useRadiologyOrders();
  });

  it("opens the first study when the queue loads", async () => {
    stubFetch([study()]);

    await radiology.fetchOrders();

    expect(radiology.selectedOrderId.value).toBe("rad-1");
    expect(radiology.selectedOrder.value?.id).toBe("rad-1");
  });

  it("prefers open work over finished work", async () => {
    stubFetch([
      study({ id: "rad-done", status: "completed" }),
      study({ id: "rad-open", status: "scheduled" }),
    ]);

    await radiology.fetchOrders();

    expect(radiology.selectedOrderId.value).toBe("rad-open");
  });

  it("still selects something when every study is finished", async () => {
    // The reported bug: filtering to Reported matched no open status, so
    // nothing was selected and the workstation opened blank.
    stubFetch([
      study({ id: "rad-a", status: "completed" }),
      study({ id: "rad-b", status: "completed" }),
    ]);

    await radiology.fetchOrders();

    expect(radiology.selectedOrderId.value).not.toBeNull();
    expect(radiology.selectedOrder.value).not.toBeNull();
  });

  it("selects nothing only when the queue is genuinely empty", async () => {
    stubFetch([]);

    await radiology.fetchOrders();

    expect(radiology.selectedOrderId.value).toBeNull();
  });

  it("highlights the patient that owns the auto-selected study", async () => {
    stubFetch([study({ patientId: "pat-7" })]);

    await radiology.fetchOrders();

    // Assigning the id alone left the queue with no patient highlighted.
    expect(radiology.selectedPatientId.value).toBe("pat-7");
  });

  it("keeps a selection that survived the refresh", async () => {
    stubFetch([study({ id: "rad-a" }), study({ id: "rad-b" })]);
    await radiology.fetchOrders();

    radiology.selectOrder("rad-b");
    await radiology.fetchOrders();

    expect(radiology.selectedOrderId.value).toBe("rad-b");
  });
});
