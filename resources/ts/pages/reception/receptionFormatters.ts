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
