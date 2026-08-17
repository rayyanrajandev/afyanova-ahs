/**
 * Shared display formatters for the reception workspace
 * =========================================================
 * Pulled out of Index.vue (2026-08-10, component-library audit) —
 * `formatClinicalDate`/`patientDisplayName`/`patientInitials` were each
 * used by more than one feature (profile, arrival intake, appointment
 * scheduling) inside a single 2300+ line file; a shared module is the
 * actual fix, not copy-pasting the same three functions into every
 * composable that gets extracted next.
 */

import type { Patient } from "@/stores/patientStore";

/**
 * Clinical date format DD MMM YYYY (Volume 0.4 §6.2) — deliberately
 * locale-invariant ("Aug", not translated): "the month is always a
 * 3-letter abbreviation, never a number, to eliminate transposition
 * errors (P1)".
 */
export function formatClinicalDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
}

/** Clinical time format HH:mm, 24-hour (matches formatClinicalDate's locale-invariant intent). */
export function formatClinicalTime(iso: string | null | undefined): string {
  if (!iso) return "—";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleTimeString("en-GB", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}

/**
 * True when an ISO datetime falls on today's date, compared in the
 * browser's local time on both sides (Volume 3.7 T4.6 follow-up) — not UTC.
 * `scheduledAt` round-trips through the backend as a UTC instant (see
 * useAppointmentScheduling.ts's own timezone bug-fix docblock), so
 * comparing raw date substrings would misjudge any appointment near
 * midnight in East Africa Time. `Date`'s local getters (`getFullYear`/
 * `getMonth`/`getDate`) already do this conversion correctly.
 */
export function isToday(iso: string | null | undefined): boolean {
  if (!iso) return false;
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return false;
  const now = new Date();
  return (
    date.getFullYear() === now.getFullYear() &&
    date.getMonth() === now.getMonth() &&
    date.getDate() === now.getDate()
  );
}

export function patientDisplayName(patient: Patient): string {
  return `${patient.name[0]?.given?.join(" ") ?? ""} ${patient.name[0]?.family ?? ""}`.trim();
}

export function patientInitials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}
