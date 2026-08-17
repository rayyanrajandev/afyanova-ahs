/**
 * Registration schema (Volume 2.1 §6.1, §6.2 / Volume 1.2 §7.1)
 * ==============================================================
 * Single source of truth for the reception registration form. Field names
 * match the backend StorePatientRequest exactly. Validation mirrors the
 * server: dateOfBirth before:today (no future, age > 0) and locale-aware
 * phone formats (Volume 0.4 §6) that normalize to +255 for Tanzania.
 *
 * 2027 Enterprise Upgrades:
 * - Bi-directional Age ↔ DOB calculation utilities
 * - Integrated financial coverage / insurance fields (Self-Pay vs Insurance)
 */

import { z } from "zod";

/**
 * Tanzania-style phone validation (Volume 0.4 §6), mirroring the backend
 * PatientPhoneNumber normalizer: +255 (12 digits), 0… (10 digits) or a bare
 * 9-digit mobile number all resolve to a valid number.
 */
export function isTanzaniaPhone(value: string): boolean {
  const digits = value.replace(/\D+/g, "");
  if (digits.length === 12 && digits.startsWith("255")) return true;
  if (digits.length === 10 && digits.startsWith("0")) return true;
  if (digits.length === 9) return true;
  return false;
}

/** DOB must be a real calendar date, not in the future, and imply age >= 0 (Volume 2.1 §6.1). */
export function isValidDateOfBirth(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;

  const [year, month, day] = value.split("-").map(Number);
  const date = new Date(year, month - 1, day);
  if (
    date.getFullYear() !== year ||
    date.getMonth() !== month - 1 ||
    date.getDate() !== day
  ) {
    return false; // 2026-02-30 is not a real date
  }

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  if (date > today) return false; // not in the future

  return true;
}

export function ageFrom(dateOfBirth: string): number {
  if (!dateOfBirth) return 0;
  const [year, month, day] = dateOfBirth.split("-").map(Number);
  if (!year || !month || !day) return 0;
  const dob = new Date(year, month - 1, day);
  const now = new Date();
  let age = now.getFullYear() - dob.getFullYear();
  const m = now.getMonth() - dob.getMonth();
  if (m < 0 || (m === 0 && now.getDate() < dob.getDate())) age -= 1;
  return Math.max(age, 0);
}

/** Convert an age in years back to an estimated January 1st Date of Birth ISO string. */
export function dobFromAge(ageInYears: number): string {
  const safeAge = Math.max(0, Math.min(130, Math.floor(ageInYears)));
  const birthYear = new Date().getFullYear() - safeAge;
  return `${birthYear}-01-01`;
}

export type RegistrationValues = z.infer<typeof registrationSchema>;

export const registrationSchema = z.object({
  firstName: z.string().min(1, "required"),
  middleName: z.string().optional(),
  lastName: z.string().min(1, "required"),
  dateOfBirth: z.string().refine(isValidDateOfBirth, "invalid_date_of_birth"),
  gender: z.enum(["male", "female", "other", "unknown"]),
  phone: z.string().refine(isTanzaniaPhone, "invalid_phone"),
  email: z.string().email("invalid_email").optional().or(z.literal("")),
  addressLine: z.string().min(1, "required"),
  region: z.string().min(1, "required"),
  district: z.string().min(1, "required"),
  countryCode: z.string().length(2, "required").default("TZ"),
  nationalId: z.string().optional(),
  
  // Financial Coverage / Insurance (2027 Enterprise Intake)
  coverageType: z.enum(["self_pay", "insurance"]).default("self_pay"),
  insuranceProvider: z.string().optional(),
  memberNumber: z.string().optional(),
  policyType: z.string().optional(),

  // Emergency Contact
  nextOfKinName: z.string().optional(),
  nextOfKinPhone: z
    .string()
    .optional()
    .refine((value) => !value || isTanzaniaPhone(value), "invalid_phone"),
});
