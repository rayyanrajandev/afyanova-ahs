/**
 * printDelivery.ts — how a clinical document actually reaches the printer
 * =======================================================================
 * Every workspace had grown its own version of the same twelve lines, and all
 * of them carried the same three faults.
 *
 * 1. `window.open("", "_blank")`. A popup, so a blocker silences it — the
 *    laboratory and radiology paths answered that with `alert()`, the reception
 *    one gave up in silence. It also flashes a window on screen, and because
 *    the popup's address is `about:blank`, the browser prints that string in
 *    the page footer of every clinical report.
 *
 * 2. A fixed `setTimeout` before `print()` — 50ms in reception, 120ms in the
 *    shared engine, 750ms in pharmacy. All three are guesses about when the
 *    document finished laying out. Lose the race and the dialog opens over a
 *    half-rendered page.
 *
 * 3. Browser headers and footers. A `@page` rule with a non-zero margin leaves
 *    the browser room to draw its own furniture there — document title, date,
 *    `about:blank`, "1/1" — on top of a signed clinical record. Reception's
 *    label had no `@page` rule at all, so a 70mm label went out centred on a
 *    sheet of A4.
 *
 * Printing from a hidden same-document iframe fixes the first two outright: no
 * popup to block, nothing to flash, and a real `load` event to wait on instead
 * of a guess. The third is handled by `pageRule()` below.
 */

export interface PrintDeliveryOptions {
  /**
   * Names the print job in the OS dialog and the spooler queue. Worth setting:
   * the alternative is a queue full of identically-named documents.
   */
  title?: string;
  /** How long to wait for fonts before printing anyway. */
  fontTimeoutMs?: number;
}

/**
 * Page geometry that keeps the browser's own header and footer off the paper.
 *
 * The margin has to be zero for that: browsers draw their furniture inside the
 * page margin, so any room left there gets used. The visual margin is restored
 * as padding on the document body, which is why every caller must apply
 * `bodyPadding` rather than setting a `@page` margin of its own.
 *
 * The honest limitation: body padding is one box spanning the whole flow, so on
 * a document that runs past one page the continuation pages carry the side
 * padding but not the top. For the labels here — always a single page — it is
 * exact, and for a two-page report it is a better trade than a URL printed
 * under a pathologist's signature. A viewer can still switch headers back on
 * from the print dialog; nothing in CSS can prevent that.
 */
export function pageRule(size: string, bodyPadding: string): string {
  return `
    @page { size: ${size}; margin: 0; }
    html, body { margin: 0; }
    body { padding: ${bodyPadding}; }
  `;
}

/** Removes the frame once the dialog is done with it, exactly once. */
function disposeLater(frame: HTMLIFrameElement, view: Window): void {
  let disposed = false;

  const dispose = (): void => {
    if (disposed) return;
    disposed = true;
    frame.remove();
  };

  // Fires when the dialog closes, whether the user printed or cancelled.
  view.addEventListener("afterprint", dispose, { once: true });

  // Safari has never fired afterprint reliably. Without this the frames
  // accumulate silently, one per print, for the life of the session.
  window.setTimeout(dispose, 60_000);
}

/**
 * Print a complete HTML document, resolving once the dialog has been opened.
 *
 * Resolving does not mean the page was printed — no browser will tell us that,
 * and a caller that waits for paper will wait forever. It means the document
 * rendered and the dialog was handed the job.
 */
export function printHtmlDocument(
  html: string,
  options: PrintDeliveryOptions = {},
): Promise<void> {
  if (typeof window === "undefined" || typeof document === "undefined") {
    return Promise.resolve();
  }

  return new Promise<void>((resolve) => {
    const frame = document.createElement("iframe");
    frame.setAttribute("aria-hidden", "true");
    frame.setAttribute("tabindex", "-1");
    // Off-screen rather than display:none — a frame that is not rendered has
    // no layout, and printing one produces a blank page.
    frame.style.cssText =
      "position:fixed;right:0;bottom:0;width:1px;height:1px;opacity:0;border:0;";

    frame.addEventListener("load", () => {
      const view = frame.contentWindow;
      const doc = frame.contentDocument;

      if (!view || !doc) {
        frame.remove();
        resolve();

        return;
      }

      if (options.title) {
        doc.title = options.title;
      }

      const send = (): void => {
        disposeLater(frame, view);
        view.focus();
        view.print();
        resolve();
      };

      // Wait for the real signal rather than guessing at a delay, but never
      // hang on it: a font that fails to load must not cost the print.
      const fonts = (doc as Document & { fonts?: FontFaceSet }).fonts;

      if (fonts?.ready) {
        let sent = false;
        const once = (): void => {
          if (sent) return;
          sent = true;
          send();
        };

        window.setTimeout(once, options.fontTimeoutMs ?? 1_500);
        void fonts.ready.then(once).catch(once);

        return;
      }

      send();
    });

    // srcdoc rather than document.write: same-origin, no parser reentry, and
    // it gives us one honest load event to hang the print on.
    frame.srcdoc = html;
    document.body.appendChild(frame);
  });
}
