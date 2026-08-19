/**
 * The counter's arithmetic.
 *
 * These mirror the cases the backend Money value object is tested with, because
 * the two have to agree: the API sends decimal strings and receives minor
 * units, and a disagreement about rounding shows up as a cashier who cannot
 * make the drawer balance.
 */

import { describe, expect, it } from "vitest";
import {
  changeDue,
  decimalToMinor,
  formatMoney,
  fromAmountInput,
  minorToDecimal,
  toAmountInput,
} from "../cashierFormatters";

describe("decimalToMinor", () => {
  it.each([
    ["15000.00", 1500000],
    ["15000", 1500000],
    ["15000.5", 1500050],
    ["0.01", 1],
    ["0.00", 0],
    ["-250.25", -25025],
  ])("parses %s to %i", (input, expected) => {
    expect(decimalToMinor(input as string)).toBe(expected);
  });

  it("treats a missing amount as nothing owed", () => {
    expect(decimalToMinor(null)).toBe(0);
    expect(decimalToMinor(undefined)).toBe(0);
    expect(decimalToMinor("")).toBe(0);
  });

  it("does not lose a cent to floating point", () => {
    // parseFloat("15000.10") * 100 is 1500009.9999999998.
    expect(decimalToMinor("15000.10")).toBe(1500010);
    expect(decimalToMinor("0.29")).toBe(29);
  });
});

describe("minorToDecimal", () => {
  it.each([
    [1500000, "15000.00"],
    [1, "0.01"],
    [0, "0.00"],
    [-25025, "-250.25"],
  ])("renders %i as %s", (input, expected) => {
    expect(minorToDecimal(input as number)).toBe(expected);
  });

  it("round-trips with decimalToMinor", () => {
    for (const minor of [0, 1, 99, 100, 1500000, 999999999]) {
      expect(decimalToMinor(minorToDecimal(minor))).toBe(minor);
    }
  });
});

describe("formatMoney", () => {
  it("drops the decimals on a whole amount, as TZS is written", () => {
    expect(formatMoney("15000.00")).toBe("TZS 15,000");
    expect(formatMoney(1500000)).toBe("TZS 15,000");
  });

  it("keeps them when there is a fraction", () => {
    expect(formatMoney("15000.50")).toBe("TZS 15,000.50");
    expect(formatMoney(1)).toBe("TZS 0.01");
  });

  it("groups thousands and honours the currency", () => {
    expect(formatMoney(123456789, "USD")).toBe("USD 1,234,567.89");
  });

  it("handles a negative variance", () => {
    expect(formatMoney(-200000)).toBe("TZS -2,000");
  });
});

describe("amount inputs", () => {
  it("shows a whole amount without trailing zeros", () => {
    expect(toAmountInput(1500000)).toBe("15000");
    expect(toAmountInput(1500050)).toBe("15000.50");
  });

  it("parses what a cashier types, ignoring stray characters", () => {
    expect(fromAmountInput("15000")).toBe(1500000);
    expect(fromAmountInput("15,000")).toBe(1500000);
    expect(fromAmountInput("TZS 15000")).toBe(1500000);
    expect(fromAmountInput("")).toBe(0);
    expect(fromAmountInput(15000)).toBe(1500000);
  });
});

describe("changeDue", () => {
  it("is the difference when the tender covers the bill", () => {
    expect(changeDue(2000000, 1500000)).toBe(500000);
  });

  it("is nothing on an exact tender", () => {
    expect(changeDue(1500000, 1500000)).toBe(0);
  });

  it("never goes negative while an amount is half-typed", () => {
    expect(changeDue(100000, 1500000)).toBe(0);
  });
});
