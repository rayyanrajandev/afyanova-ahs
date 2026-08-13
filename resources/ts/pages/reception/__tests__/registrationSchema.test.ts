/**
 * Registration schema (Volume 3.7 T2.1 + T2.2, Volume 2.1 §6.1)
 * =============================================================
 * DOB: not in the future, age > 0 (T2.1).
 * Phone: Tanzania locale-aware formats pass; nonsense fails (T2.2).
 */

import { describe, expect, it } from "vitest";
import {
  ageFrom,
  isTanzaniaPhone,
  isValidDateOfBirth,
  registrationSchema,
} from "../registrationSchema";

const validDob = () => "1990-06-15";

describe("T2.1 — date of birth rules", () => {
  it("accepts a past, real DOB that implies age > 0", () => {
    expect(isValidDateOfBirth(validDob())).toBe(true);
  });

  it("rejects a future DOB", () => {
    const future = new Date(Date.now() + 60_000).toISOString().slice(0, 10);
    expect(isValidDateOfBirth(future)).toBe(false);
  });

  it("rejects today (age 0)", () => {
    expect(isValidDateOfBirth(new Date().toISOString().slice(0, 10))).toBe(
      false,
    );
  });

  it("rejects an impossible calendar date like 2026-02-30", () => {
    expect(isValidDateOfBirth("2026-02-30")).toBe(false);
  });

  it("computes the age from a birth date", () => {
    const now = new Date();
    const dob = `${now.getFullYear() - 20}-01-01`;
    expect(ageFrom(dob)).toBeGreaterThanOrEqual(19);
  });
});

describe("T2.2 — phone format (Volume 0.4 §6, Tanzania)", () => {
  it("accepts +255 full international format", () => {
    expect(isTanzaniaPhone("+255785123456")).toBe(true);
  });

  it("accepts 255 local international format", () => {
    expect(isTanzaniaPhone("255785123456")).toBe(true);
  });

  it("accepts a 0-prefixed 10-digit local number", () => {
    expect(isTanzaniaPhone("0785123456")).toBe(true);
  });

  it("accepts a bare 9-digit mobile number", () => {
    expect(isTanzaniaPhone("785123456")).toBe(true);
  });

  it("rejects a short / invalid number", () => {
    expect(isTanzaniaPhone("12345")).toBe(false);
    expect(isTanzaniaPhone("abc")).toBe(false);
  });
});

function baseValues(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    firstName: "Asha",
    lastName: "Nguvumali",
    dateOfBirth: validDob(),
    gender: "female",
    phone: "+255785123456",
    email: "",
    addressLine: "Nyerere Road",
    region: "Mwanza",
    district: "Nyamagana",
    countryCode: "TZ",
    ...overrides,
  };
}

describe("registrationSchema end-to-end", () => {
  it("passes a fully valid payload", () => {
    expect(registrationSchema.safeParse(baseValues()).success).toBe(true);
  });

  it("fails on a future DOB (T2.1)", () => {
    const future = "2099-01-01";
    const result = registrationSchema.safeParse(
      baseValues({ dateOfBirth: future }),
    );
    expect(result.success).toBe(false);
  });

  it("fails on an invalid phone (T2.2)", () => {
    const result = registrationSchema.safeParse(baseValues({ phone: "77" }));
    expect(result.success).toBe(false);
  });

  it("fails when required fields are missing", () => {
    const result = registrationSchema.safeParse(baseValues({ firstName: "" }));
    expect(result.success).toBe(false);
  });
});
