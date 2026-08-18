import { pageRule, printHtmlDocument } from "@/services/print/printDelivery";
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

/**
 * Print the patient wristband label.
 *
 * This carried no `@page` rule at all, so a 54x25mm label was laid out on
 * whatever the printer defaulted to — in practice a sheet of A4 with the label
 * in one corner and the browser's header above it. It now declares its own
 * stock, and prints through the shared delivery like every other document.
 */
export function printPatientLabel(patient: Patient): void {
  const html = `<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Patient Label</title>
  <style>
    ${pageRule("54mm 25mm", "2mm")}
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      color: #000;
    }
  </style>
</head>
<body>${patientLabelMarkup(patient)}</body>
</html>`;

  void printHtmlDocument(html, {
    title: `Label — ${patient.identifier[0]?.value ?? "Patient"}`,
  });
}
