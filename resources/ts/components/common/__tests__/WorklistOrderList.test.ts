/**
 * The shared worklist row exists to stop two specific defects recurring: a
 * clinical name cut short by a fixed width, and a status carried by colour
 * alone. Both were live in the panels this replaced.
 */

import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import WorklistOrderList, {
  type WorklistOrderItem,
} from "../WorklistOrderList.vue";

const LONG_NAME = "Amoxicillin/Clavulanate 875mg/125mg film-coated tablet";

function item(overrides: Partial<WorklistOrderItem> = {}): WorklistOrderItem {
  return {
    id: "rx-1",
    label: LONG_NAME,
    detail: "875mg · PO · BD",
    tone: "waiting",
    toneLabel: "Pending",
    ...overrides,
  };
}

function render(items: WorklistOrderItem[], selectedId: string | null = null) {
  return mount(WorklistOrderList, { props: { items, selectedId } });
}

describe("WorklistOrderList", () => {
  it("never caps the clinical name at a fixed width", () => {
    const label = render([item()]).get("[title]");

    // A max-w-* on the label is what truncated hydrALAZINE vs hydrOXYzine
    // into the same string in the panels this replaced.
    expect(label.classes().some((c) => c.startsWith("max-w-"))).toBe(false);
    expect(label.classes()).toContain("flex-1");
  });

  it("keeps the full name reachable when the row does truncate", () => {
    const wrapper = render([item()]);

    expect(wrapper.get("[title]").attributes("title")).toBe(LONG_NAME);
  });

  it("writes the status out rather than relying on the dot", () => {
    const wrapper = render([item({ toneLabel: "Partially dispensed" })]);

    expect(wrapper.text()).toContain("Partially dispensed");
  });

  it("gives every tone a distinct dot", () => {
    const tones: WorklistOrderItem["tone"][] = [
      "waiting",
      "active",
      "progress",
      "released",
      "verified",
      "cancelled",
    ];

    const wrapper = render(
      tones.map((tone, i) => item({ id: `o-${i}`, tone, toneLabel: tone })),
    );

    const dots = wrapper
      .findAll(".rounded-full")
      .map((d) => d.classes().find((c) => c.startsWith("bg-")));

    expect(new Set(dots).size).toBe(tones.length);
  });

  it("keeps the row to a single line", () => {
    const row = render([item()]).get("button");

    // A stacked row doubles the height of every patient card in the queue.
    expect(row.classes()).toContain("items-center");
    expect(row.classes()).not.toContain("flex-col");
  });

  it("announces the detail it does not draw", () => {
    const wrapper = render([item()]);

    // The label carries a strength of its own, so match the whole detail.
    expect(wrapper.text()).not.toContain("875mg · PO · BD");
    expect(wrapper.get("button").attributes("aria-label")).toContain(
      "875mg · PO · BD",
    );
  });

  it("is reachable by keyboard, not click only", () => {
    const wrapper = render([item()]);

    expect(wrapper.findAll("button")).toHaveLength(1);
  });

  it("emits the id of the row that was chosen", async () => {
    const wrapper = render([item(), item({ id: "rx-2", label: "Metformin" })]);

    await wrapper.findAll("button")[1].trigger("click");

    expect(wrapper.emitted("select")).toEqual([["rx-2"]]);
  });

  it("marks the open row for assistive technology", () => {
    const wrapper = render([item(), item({ id: "rx-2" })], "rx-2");
    const rows = wrapper.findAll("button");

    expect(rows[0].attributes("aria-current")).toBeUndefined();
    expect(rows[1].attributes("aria-current")).toBe("true");
  });
});
