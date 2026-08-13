/**
 * Patient wristband/label markup (Volume 2.1 §5.2 W5, §6.3 step 4, Volume 3.7 T2.7)
 * =================================================================================
 * Builds the printable label payload for a patient. Kept as a pure function so
 * the payload can be unit-tested and reused by the Ctrl+P shortcut and the
 * "Print Label" action button (both must produce identical markup).
 */

import type { Patient } from "@/stores/patientStore";

export function patientLabelMarkup(patient: Patient): string {
  const given = patient.name[0]?.given?.join(" ") ?? "";
  const family = patient.name[0]?.family ?? "";
  const mrn = patient.identifier[0]?.value ?? "";
  const dob = patient.birthDate;
  const age = patient.meta.extension.age;

  return [
    '<div style="font-family: monospace; padding: 8px; border-bottom: 1px dashed #333;">',
    `<div style="font-size: 9px; letter-spacing: 1px; text-transform: uppercase;">Afyanova AHS</div>`,
    `<div style="font-size: 14px; font-weight: bold;">${escapeHtml(given)} ${escapeHtml(family)}</div>`,
    `<div style="font-size: 12px;">MRN ${escapeHtml(mrn)}</div>`,
    `<div style="font-size: 12px;">DOB ${escapeHtml(dob)} (${age}y)</div>`,
    "</div>",
  ].join("");
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

/** Opens a print-only window with the label and triggers the print dialog. */
export function printPatientLabel(patient: Patient): void {
  const markup = patientLabelMarkup(patient);
  if (typeof window === "undefined") return;

  const printWindow = window.open("", "_blank", "width=300,height=150");
  if (!printWindow) return;

  printWindow.document.write(
    `<!doctype html><html><head><title>Patient Label</title></head><body style="margin:0;">${markup}</body></html>`,
  );
  printWindow.document.close();
  printWindow.focus();
  // Delay lets the document paint before the print dialog opens.
  setTimeout(() => {
    printWindow.print();
    printWindow.close();
  }, 50);
}
