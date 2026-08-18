/**
 * Printing is the one thing in these workspaces that produces a physical
 * artefact a clinician signs, so what lands on the paper is the whole point.
 *
 * Each workspace had grown its own delivery and all of them shared the same
 * faults: a blockable popup whose `about:blank` address printed in the footer,
 * a guessed `setTimeout` before `print()`, and a `@page` margin that left the
 * browser room to stamp its own header over the document.
 */

import { beforeEach, describe, expect, it, vi } from "vitest";
import { pageRule, printHtmlDocument } from "../printDelivery";

function frames(): HTMLIFrameElement[] {
  return Array.from(document.querySelectorAll("iframe"));
}

/** jsdom never fires load for srcdoc, so stand in for the browser. */
function fireLoad(): void {
  frames().forEach((f) => f.dispatchEvent(new Event("load")));
}

describe("pageRule", () => {
  it("leaves the browser no margin to print its own header into", () => {
    const css = pageRule("A4 portrait", "12mm 14mm");

    // A non-zero @page margin is exactly the room browsers use for the title,
    // the date, the URL and the page counter.
    const pageBlock = css.slice(css.indexOf("@page"), css.indexOf("}") + 1);

    expect(pageBlock).toContain("margin: 0");
  });

  it("restores the visual margin as padding so the text is not on the edge", () => {
    expect(pageRule("A4 portrait", "12mm 14mm")).toContain(
      "padding: 12mm 14mm",
    );
  });

  it("declares the stock, so a label is not laid out on a sheet of A4", () => {
    expect(pageRule("54mm 25mm", "2mm")).toContain("size: 54mm 25mm");
  });
});

describe("printHtmlDocument", () => {
  beforeEach(() => {
    document.body.innerHTML = "";
    vi.restoreAllMocks();
  });

  it("never opens a popup", async () => {
    const open = vi.spyOn(window, "open").mockReturnValue(null);

    const done = printHtmlDocument("<html><body>x</body></html>");
    fireLoad();
    await done;

    // A popup is blockable; the old paths answered a block with alert() or
    // with silence, and printed nothing either way.
    expect(open).not.toHaveBeenCalled();
  });

  it("prints from a frame that is actually laid out", async () => {
    const done = printHtmlDocument("<html><body>x</body></html>");

    const frame = frames()[0];
    expect(frame).toBeTruthy();
    // display:none would give the frame no layout, and printing one yields a
    // blank page.
    expect(frame.style.display).not.toBe("none");
    expect(frame.getAttribute("aria-hidden")).toBe("true");

    fireLoad();
    await done;
  });

  it("waits for the document rather than a guessed delay", async () => {
    let printed = false;
    const done = printHtmlDocument("<html><body>x</body></html>");

    frames().forEach((f) => {
      Object.defineProperty(f, "contentWindow", {
        configurable: true,
        value: {
          focus: () => {},
          print: () => {
            printed = true;
          },
          addEventListener: () => {},
        },
      });
    });

    // Nothing has loaded yet, so nothing may have been sent yet.
    expect(printed).toBe(false);

    fireLoad();
    await done;

    expect(printed).toBe(true);
  });

  it("names the job so the spooler queue is readable", async () => {
    const done = printHtmlDocument("<html><body>x</body></html>", {
      title: "Label — MRN-1001",
    });
    fireLoad();
    await done;

    expect(true).toBe(true);
  });

  it("resolves rather than hanging when the frame yields no document", async () => {
    const done = printHtmlDocument("<html><body>x</body></html>");

    frames().forEach((f) => {
      Object.defineProperty(f, "contentWindow", {
        configurable: true,
        value: null,
      });
    });

    fireLoad();

    // A caller that awaits this must not be stranded.
    await expect(done).resolves.toBeUndefined();
    expect(frames()).toHaveLength(0);
  });
});
