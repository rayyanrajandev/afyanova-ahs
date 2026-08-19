/**
 * cashierFormatters — money in and out of the counter
 * ====================================================
 * The one place that converts between minor units and what a person reads or
 * types. The API speaks integer minor units on the way in and decimal strings
 * on the way out, and neither is what belongs on a screen or in an input.
 *
 * Kept in a single module so no component ever divides by 100 inline — that is
 * how a rounding rule ends up being defined in four places and agreeing in
 * three of them.
 */

/** Minor units per major unit. Two everywhere the ledger currently runs. */
const MINOR_UNIT_SCALE = 100;

/**
 * A decimal string from the API ("15000.00") to minor units (1500000).
 *
 * Parsed by splitting on the decimal point rather than multiplying a float:
 * `parseFloat("15000.10") * 100` is 1500009.9999999998, and rounding it back
 * happens to work today and is not something to rely on for money.
 */
export function decimalToMinor(amount: string | null | undefined): number {
  if (!amount) return 0;

  const trimmed = amount.trim();
  const negative = trimmed.startsWith("-");
  const [whole = "0", fraction = ""] = trimmed.replace("-", "").split(".");

  const padded = fraction.padEnd(2, "0").slice(0, 2);
  const minor = Number(whole) * MINOR_UNIT_SCALE + Number(padded || "0");

  return negative ? -minor : minor;
}

/** Minor units to a plain decimal string, for sending back to the API. */
export function minorToDecimal(minor: number): string {
  const negative = minor < 0;
  const abs = Math.abs(Math.trunc(minor));
  const whole = Math.floor(abs / MINOR_UNIT_SCALE);
  const fraction = String(abs % MINOR_UNIT_SCALE).padStart(2, "0");

  return `${negative ? "-" : ""}${whole}.${fraction}`;
}

/**
 * What a cashier reads: grouped, with the currency in front.
 *
 * TZS has no circulating subunit, so whole amounts drop the decimals — a
 * receipt reading "TZS 15,000" is what a patient in Dar es Salaam expects,
 * and "TZS 15,000.00" reads as a foreign document.
 */
export function formatMoney(
  amount: string | number | null | undefined,
  currencyCode = "TZS",
): string {
  const minor = typeof amount === "number" ? amount : decimalToMinor(amount);
  const negative = minor < 0;
  const abs = Math.abs(minor);

  const whole = Math.floor(abs / MINOR_UNIT_SCALE);
  const fraction = abs % MINOR_UNIT_SCALE;

  const grouped = whole.toLocaleString("en-US");
  const body =
    fraction === 0
      ? grouped
      : `${grouped}.${String(fraction).padStart(2, "0")}`;

  // The sign sits with the number, not in front of the currency: a short
  // drawer reads "TZS -2,000", which is how a variance is written on paper.
  return `${currencyCode} ${negative ? "-" : ""}${body}`;
}

/**
 * What a cashier types: the major-unit figure alone, no currency, no grouping,
 * so it can go straight back into a number input without being re-parsed.
 */
export function toAmountInput(minor: number): string {
  return minor % MINOR_UNIT_SCALE === 0
    ? String(Math.trunc(minor / MINOR_UNIT_SCALE))
    : minorToDecimal(minor);
}

/** A typed major-unit figure back to minor units. */
export function fromAmountInput(value: string | number): number {
  if (typeof value === "number") {
    return Math.round(value * MINOR_UNIT_SCALE);
  }

  const cleaned = value.replace(/[^\d.-]/g, "");

  return cleaned === "" ? 0 : decimalToMinor(cleaned);
}

/**
 * Change owed, floored at zero.
 *
 * Never negative: the counter refuses an under-tender outright, so a negative
 * figure here would only ever be a display artefact of a half-typed amount.
 */
export function changeDue(tenderedMinor: number, dueMinor: number): number {
  return Math.max(0, tenderedMinor - dueMinor);
}
