/**
 * cashierReceiptPrint — the paper the patient walks away with
 * ============================================================
 * An 80mm thermal receipt, printed from the snapshot the ledger stored when
 * the payment was taken — never re-derived from the charges.
 *
 * That distinction matters: a charge can later be refunded, a tariff can be
 * superseded, and a reprint must still reproduce what was handed over at the
 * counter. Rebuilding the lines at print time would quietly rewrite history
 * on the one document a patient can produce in a dispute.
 */

import { pageRule, printHtmlDocument } from "@/services/print/printDelivery";
import { formatMoney } from "./cashierFormatters";
import type { CashierReceipt } from "./composables/useCashierPayment";

export interface ReceiptPrintOptions {
  facilityName?: string;
  facilityPhone?: string;
  patientName?: string | null;
  patientNumber?: string | null;
  cashierName?: string | null;
  /** Marks the paper, so a duplicate is never mistaken for an original. */
  isReprint?: boolean;
}

function escapeHtml(value: string | null | undefined): string {
  return String(value ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      })[c] ?? c,
  );
}

export function printCashierReceipt(
  receipt: CashierReceipt,
  options: ReceiptPrintOptions = {},
): Promise<void> {
  const facilityName = options.facilityName || "AFYANOVA HEALTH SYSTEM";
  const currency = receipt.snapshot?.currencyCode ?? receipt.currencyCode;
  const lines = receipt.snapshot?.lines ?? [];

  const rows = lines
    .map(
      (line) => `
        <tr>
          <td class="desc">
            ${escapeHtml(line.description)}
            ${line.quantity !== 1 ? `<span class="qty">x${line.quantity}</span>` : ""}
          </td>
          <td class="amt">${escapeHtml(formatMoney(line.amount, currency))}</td>
        </tr>`,
    )
    .join("");

  const issuedAt = receipt.snapshot?.issuedAt ?? receipt.issuedAt ?? "";
  const issuedLabel = issuedAt ? new Date(issuedAt).toLocaleString() : "";

  const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>${escapeHtml(receipt.receiptNumber)}</title>
<style>
  ${pageRule("80mm auto", "4mm")}
  /* Literal colours, not design tokens: this document is rendered by the
     browser's print pipeline to a thermal printer, where the app's theme
     variables do not exist and everything is ink or no ink. */
  body {
    font-family: "Courier New", ui-monospace, monospace;
    font-size: 11px;
    line-height: 1.45;
    color: black;
  }
  .center { text-align: center; }
  .facility { font-size: 13px; font-weight: 700; letter-spacing: .5px; }
  .rule { border-top: 1px dashed black; margin: 6px 0; }
  .meta { display: flex; justify-content: space-between; }
  table { width: 100%; border-collapse: collapse; }
  td { vertical-align: top; padding: 2px 0; }
  td.amt { text-align: right; white-space: nowrap; padding-left: 6px; }
  .qty { display: block; font-size: 10px; }
  .totals td { padding: 1px 0; }
  .grand td { font-weight: 700; font-size: 12px; padding-top: 4px; }
  .reprint {
    text-align: center; font-weight: 700; letter-spacing: 2px;
    border: 1px solid black; padding: 2px; margin-bottom: 6px;
  }
  .foot { margin-top: 8px; font-size: 10px; text-align: center; }
</style>
</head>
<body>
  ${options.isReprint ? '<div class="reprint">REPRINT</div>' : ""}

  <div class="center facility">${escapeHtml(facilityName)}</div>
  ${options.facilityPhone ? `<div class="center">${escapeHtml(options.facilityPhone)}</div>` : ""}
  <div class="center">PAYMENT RECEIPT</div>

  <div class="rule"></div>

  <div class="meta"><span>Receipt</span><span>${escapeHtml(receipt.receiptNumber)}</span></div>
  <div class="meta"><span>Payment</span><span>${escapeHtml(receipt.snapshot?.paymentNumber ?? "")}</span></div>
  <div class="meta"><span>Date</span><span>${escapeHtml(issuedLabel)}</span></div>
  ${options.patientName ? `<div class="meta"><span>Patient</span><span>${escapeHtml(options.patientName)}</span></div>` : ""}
  ${options.patientNumber ? `<div class="meta"><span>MRN</span><span>${escapeHtml(options.patientNumber)}</span></div>` : ""}
  ${options.cashierName ? `<div class="meta"><span>Served by</span><span>${escapeHtml(options.cashierName)}</span></div>` : ""}

  <div class="rule"></div>

  <table>${rows}</table>

  <div class="rule"></div>

  <table class="totals">
    <tr class="grand">
      <td>TOTAL</td>
      <td class="amt">${escapeHtml(formatMoney(receipt.snapshot?.total ?? receipt.total, currency))}</td>
    </tr>
    <tr>
      <td>Cash</td>
      <td class="amt">${escapeHtml(formatMoney(receipt.snapshot?.tendered ?? "0", currency))}</td>
    </tr>
    <tr>
      <td>Change</td>
      <td class="amt">${escapeHtml(formatMoney(receipt.snapshot?.change ?? "0", currency))}</td>
    </tr>
  </table>

  <div class="rule"></div>

  <div class="foot">
    Paid in advance of service.<br>
    Please keep this receipt.
  </div>
</body>
</html>`;

  return printHtmlDocument(html, { title: receipt.receiptNumber });
}
