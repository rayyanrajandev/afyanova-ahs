/**
 * cashierHardwareDriver — POS hardware integration
 * ==================================================
 * Direct ESC/POS printing over WebUSB / WebSerial for standard 80mm thermal
 * printers, automatic drawer kick pulses, and auto-cut commands.
 *
 * Graceful fallback: when no USB/Serial device is connected or supported by
 * the browser, operations resolve cleanly so the workspace can seamlessly
 * fall back to the standard HTML print pipeline.
 */

import { formatMoney } from "./cashierFormatters";
import type { CashierReceipt } from "./composables/useCashierPayment";
import type { ReceiptPrintOptions } from "./cashierReceiptPrint";

// Standard ESC/POS Control Constants
const ESC = 0x1b;
const GS = 0x1d;

/**
 * Encodes text to standard CP437 / ASCII Uint8Array bytes.
 */
function encodeAscii(text: string): Uint8Array {
  const bytes = new Uint8Array(text.length);
  for (let i = 0; i < text.length; i++) {
    bytes[i] = text.charCodeAt(i) & 0xff;
  }
  return bytes;
}

/**
 * Formats two strings into a fixed-width line (e.g. Left description, Right amount).
 */
function formatTwoColumnLine(left: string, right: string, width = 42): string {
  const maxLeft = Math.max(0, width - right.length - 1);
  const truncatedLeft = left.length > maxLeft ? left.slice(0, maxLeft) : left;
  const spaces = " ".repeat(Math.max(1, width - truncatedLeft.length - right.length));
  return `${truncatedLeft}${spaces}${right}\n`;
}

/**
 * Builds the binary ESC/POS payload for an 80mm receipt.
 */
export function buildEscPosReceipt(
  receipt: CashierReceipt,
  options: ReceiptPrintOptions = {},
  columnWidth = 42,
): Uint8Array {
  const parts: number[] = [];

  // 1. Initialize printer
  parts.push(ESC, 0x40); // ESC @

  // 2. Centered Header
  parts.push(ESC, 0x61, 1); // Align center
  parts.push(ESC, 0x45, 1); // Bold ON

  if (options.isReprint) {
    parts.push(...encodeAscii("*** REPRINT ***\n"));
  }

  const facilityName = (options.facilityName || "AFYANOVA HEALTH SYSTEM").toUpperCase();
  parts.push(...encodeAscii(`${facilityName}\n`));
  parts.push(ESC, 0x45, 0); // Bold OFF

  if (options.facilityPhone) {
    parts.push(...encodeAscii(`Tel: ${options.facilityPhone}\n`));
  }
  parts.push(...encodeAscii("PAYMENT RECEIPT\n"));
  parts.push(...encodeAscii("-".repeat(columnWidth) + "\n"));

  // 3. Metadata (Left aligned)
  parts.push(ESC, 0x61, 0); // Align left
  parts.push(...encodeAscii(`Receipt:  ${receipt.receiptNumber}\n`));
  if (receipt.snapshot?.paymentNumber) {
    parts.push(...encodeAscii(`Payment:  ${receipt.snapshot.paymentNumber}\n`));
  }
  const issuedAt = receipt.snapshot?.issuedAt ?? receipt.issuedAt ?? "";
  if (issuedAt) {
    parts.push(...encodeAscii(`Date:     ${new Date(issuedAt).toLocaleString()}\n`));
  }
  if (options.patientName) {
    parts.push(...encodeAscii(`Patient:  ${options.patientName}\n`));
  }
  if (options.patientNumber) {
    parts.push(...encodeAscii(`MRN:      ${options.patientNumber}\n`));
  }
  if (options.cashierName) {
    parts.push(...encodeAscii(`Served:   ${options.cashierName}\n`));
  }
  parts.push(...encodeAscii("-".repeat(columnWidth) + "\n"));

  // 4. Line Items
  const currency = receipt.snapshot?.currencyCode ?? receipt.currencyCode;
  const lines = receipt.snapshot?.lines ?? [];

  for (const line of lines) {
    const desc = line.quantity !== 1 ? `${line.description} x${line.quantity}` : line.description;
    const amt = formatMoney(line.amount, currency);
    parts.push(...encodeAscii(formatTwoColumnLine(desc, amt, columnWidth)));
  }

  parts.push(...encodeAscii("-".repeat(columnWidth) + "\n"));

  // 5. Totals (Bold Grand Total)
  const totalAmt = formatMoney(receipt.snapshot?.total ?? receipt.total, currency);
  parts.push(ESC, 0x45, 1); // Bold ON
  parts.push(...encodeAscii(formatTwoColumnLine("TOTAL DUE", totalAmt, columnWidth)));
  parts.push(ESC, 0x45, 0); // Bold OFF

  if (receipt.snapshot?.tendered) {
    const tenderedAmt = formatMoney(receipt.snapshot.tendered, currency);
    parts.push(...encodeAscii(formatTwoColumnLine("Cash Tendered", tenderedAmt, columnWidth)));
  }
  if (receipt.snapshot?.change) {
    const changeAmt = formatMoney(receipt.snapshot.change, currency);
    parts.push(...encodeAscii(formatTwoColumnLine("Change", changeAmt, columnWidth)));
  }

  parts.push(...encodeAscii("-".repeat(columnWidth) + "\n"));

  // 6. Footer (Centered)
  parts.push(ESC, 0x61, 1); // Align center
  parts.push(...encodeAscii("Paid in advance of service.\nPlease keep this receipt.\n\n\n"));

  // 7. Auto Paper Cut: GS V 66 0 (Cut with feed)
  parts.push(GS, 0x56, 66, 0);

  return new Uint8Array(parts);
}

/**
 * Standard ESC/POS Solenoid Cash Drawer Kick pulse command.
 * ESC p m t1 t2 -> Pulse to pin 2 (m=0), on 50ms (t1=25), off 500ms (t2=250).
 */
export function buildCashDrawerKickCommand(): Uint8Array {
  return new Uint8Array([ESC, 0x70, 0x00, 0x19, 0xfa]);
}

/**
 * Checks if modern Web hardware APIs (WebUSB or WebSerial) are available in the browser.
 */
export function isWebHardwareSupported(): boolean {
  return typeof navigator !== "undefined" && ("usb" in navigator || "serial" in navigator);
}
