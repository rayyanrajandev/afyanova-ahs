/**
 * clinicalPrintEngine.ts — Hospital Document & Clinical Print Engine (2027 Standard)
 * ===================================================================================
 * Universal enterprise print layout engine shared across all AfyaNova workspaces:
 * - Reception: Patient Identity Cards, Wristbands, Registration Intake Summaries
 * - Clinician: Consultation Notes, Treatment Plans, Discharge Summaries
 * - Nursing: Vital Signs Flowsheets, Inpatient Nursing Assessments, MAR Slips
 * - Laboratory: Official ISO 15189 Diagnostic Reports (Single & Multi-Test Consolidated)
 * - Radiology: Diagnostic Imaging & Ultrasound Examination Reports
 * - Pharmacy: Prescription Fulfillment Slips, POS Medication Dispensing Invoices
 * - Cashier: Official Revenue Receipts, Billing Settlement Statements
 */

import { pageRule, printHtmlDocument } from "./printDelivery";

export interface FacilityPrintInfo {
  name?: string;
  subtitle?: string;
  accreditation?: string;
  phone?: string;
  email?: string;
  location?: string;
}

export interface PatientPrintDemographics {
  name: string;
  mrn: string;
  age?: string;
  gender?: string;
  clinician?: string;
  department?: string;
  encounterNumber?: string;
  issuedDate?: string;
}

export interface DocumentSignatureInfo {
  title: string;
  name: string;
  designation?: string;
  timestamp?: string;
  isVerified?: boolean;
}

export interface ClinicalDocumentOptions {
  facility?: FacilityPrintInfo;
  documentTitle: string;
  documentBadge?: string;
  documentBadgeColor?: string;
  documentNumber?: string;
  patient: PatientPrintDemographics;
  bodyHtml: string;
  remarksTitle?: string;
  remarksContent?: string;
  signatures?: DocumentSignatureInfo[];
  verificationHash?: string;
  footerNote?: string;
}

export function escapeHtml(value: string | number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

export function formatPrintDate(dateStr: string | null | undefined): string {
  if (!dateStr) return "—";
  try {
    const d = new Date(dateStr);
    return d.toLocaleString("en-GB", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      hour12: false,
    });
  } catch {
    return dateStr;
  }
}

/**
 * Compiles a complete, standard A4 clinical document HTML string.
 */
export function compileClinicalDocumentHtml(
  opts: ClinicalDocumentOptions,
): string {
  const facility: FacilityPrintInfo = {
    name: opts.facility?.name || "AfyaNova Automated Clinical Laboratories",
    subtitle:
      opts.facility?.subtitle ||
      "Specialized Diagnostic Pathology & Molecular Medicine",
    accreditation:
      opts.facility?.accreditation ||
      "ISO 15189:2022 Medical Laboratories Standard • CLSI Compliant",
    phone: opts.facility?.phone || "+255 22 212 9000",
    email: opts.facility?.email || "clinical-director@afyanova.health",
    location: opts.facility?.location || "Dar es Salaam, Tanzania",
  };

  const badgeColor = opts.documentBadgeColor || "#059669";
  const badgeText = opts.documentBadge || "FINAL VERIFIED";
  const issuedDate =
    opts.patient.issuedDate || formatPrintDate(new Date().toISOString());

  // Render Signatures block
  let signaturesHtml = "";
  if (opts.signatures && opts.signatures.length > 0) {
    const sigCells = opts.signatures
      .map(
        (sig, idx) => `
        <td style="width: ${Math.floor(100 / opts.signatures!.length)}%; vertical-align: bottom; ${idx > 0 ? "text-align: right;" : ""}">
          <div style="font-size: 10px; color: ${sig.isVerified ? "#059669" : "#64748b"}; text-transform: uppercase; font-weight: 700; margin-bottom: 2px;">
            ${sig.isVerified ? "✓ " : ""}${escapeHtml(sig.title)}
          </div>
          <div style="font-weight: 700; font-size: 12px; color: #0f172a;">${escapeHtml(sig.name)}</div>
          ${sig.designation ? `<div style="font-size: 10px; color: #64748b;">${escapeHtml(sig.designation)}</div>` : ""}
          <div style="font-size: 10px; color: #64748b; font-family: monospace; margin-top: 2px;">${formatPrintDate(sig.timestamp)}</div>
        </td>
      `,
      )
      .join("");

    signaturesHtml = `
      <table style="width: 100%; border-collapse: collapse; margin-top: 20px; border-top: 1px solid #cbd5e1; padding-top: 14px;">
        <tr>${sigCells}</tr>
      </table>
    `;
  }

  // Render Remarks block
  let remarksHtml = "";
  if (opts.remarksContent && opts.remarksContent.trim()) {
    remarksHtml = `
      <div style="margin-top: 14px; margin-bottom: 14px; border: 1px solid #e2e8f0; border-left: 3.5px solid #0284c7; background-color: #f8fafc; padding: 9px 12px; border-radius: 4px;">
        <div style="font-size: 10px; font-weight: 700; color: #0284c7; text-transform: uppercase; margin-bottom: 2px;">
          ${escapeHtml(opts.remarksTitle || "Clinical Remarks / Interpretation")}
        </div>
        <p style="font-size: 11.5px; color: #1e293b; line-height: 1.45;">
          ${escapeHtml(opts.remarksContent)}
        </p>
      </div>
    `;
  }

  return `
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>${escapeHtml(opts.documentTitle)} — ${escapeHtml(opts.patient.name)} (${escapeHtml(opts.patient.mrn)})</title>
  <style>
    ${pageRule("A4 portrait", "12mm 14mm")}
    *, *::before, *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      color: #1f2937;
      background: #ffffff;
      line-height: 1.35;
      font-size: 11.5px;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .report-wrapper {
      max-width: 800px;
      margin: 0 auto;
      background: #ffffff;
    }
    .header-table {
      width: 100%;
      border-collapse: collapse;
      border-bottom: 2px solid #0f172a;
      padding-bottom: 10px;
      margin-bottom: 12px;
    }
    .clinic-title {
      font-size: 16px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -0.3px;
      text-transform: uppercase;
    }
    .clinic-subtitle {
      font-size: 11.5px;
      font-weight: 600;
      color: #0284c7;
      margin-top: 1px;
    }
    .clinic-meta {
      font-size: 9.5px;
      color: #64748b;
      margin-top: 2px;
    }
    .badge-report {
      display: inline-block;
      padding: 3px 8px;
      font-size: 10.5px;
      font-weight: 800;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      border-radius: 4px;
      border: 1.5px solid ${badgeColor};
      color: ${badgeColor};
      background: #ffffff;
    }
    .section-title {
      font-size: 10.5px;
      font-weight: 700;
      color: #475569;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 5px;
    }
    .meta-box {
      width: 100%;
      border-collapse: collapse;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 5px;
      margin-bottom: 14px;
    }
    .meta-box td {
      padding: 6px 10px;
      vertical-align: top;
      font-size: 11px;
    }
    .meta-label {
      font-size: 9.5px;
      font-weight: 600;
      color: #64748b;
      text-transform: uppercase;
      margin-bottom: 1px;
      display: block;
    }
    .meta-val {
      font-weight: 700;
      color: #0f172a;
    }
    .meta-mono {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    }
    .results-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 6px;
      margin-bottom: 14px;
      border: 1px solid #e2e8f0;
    }
    .results-table th {
      background-color: #f1f5f9;
      color: #334155;
      font-size: 10.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 7px 10px;
      border-bottom: 1.5px solid #cbd5e1;
      text-align: left;
    }
    .results-table td {
      padding: 7px 10px;
      font-size: 11px;
    }
    .disclaimer {
      font-size: 9px;
      color: #94a3b8;
      text-align: center;
      margin-top: 16px;
      border-top: 1px dashed #e2e8f0;
      padding-top: 8px;
    }
  </style>
</head>
<body>
  <div class="report-wrapper">
    <!-- Facility Header -->
    <table class="header-table">
      <tr>
        <td style="width: 65%; vertical-align: top;">
          <div class="clinic-title">${escapeHtml(facility.name)}</div>
          <div class="clinic-subtitle">${escapeHtml(facility.subtitle)}</div>
          <div class="clinic-meta">${escapeHtml(facility.accreditation)}</div>
          <div class="clinic-meta">${escapeHtml(facility.location)} • Tel: ${escapeHtml(facility.phone)}</div>
        </td>
        <td style="width: 35%; text-align: right; vertical-align: top;">
          <div class="badge-report">${badgeText}</div>
          <div style="margin-top: 5px; font-family: monospace; font-size: 10.5px; color: #475569;">
            ${opts.documentNumber ? `Ref: <strong>${escapeHtml(opts.documentNumber)}</strong>` : ""}
          </div>
          <div style="font-size: 9.5px; color: #94a3b8; margin-top: 2px;">
            Issued: ${issuedDate}
          </div>
        </td>
      </tr>
    </table>

    <!-- Patient Demographics Box -->
    <div class="section-title">Patient Demographics &amp; Encounter Details</div>
    <table class="meta-box">
      <tr>
        <td style="width: 25%;">
          <span class="meta-label">Patient Full Name</span>
          <span class="meta-val">${escapeHtml(opts.patient.name)}</span>
        </td>
        <td style="width: 25%;">
          <span class="meta-label">Hospital MRN</span>
          <span class="meta-val meta-mono" style="color: #0284c7;">${escapeHtml(opts.patient.mrn)}</span>
        </td>
        <td style="width: 25%;">
          <span class="meta-label">Age / Gender</span>
          <span class="meta-val">${escapeHtml(opts.patient.age || "—")} / ${escapeHtml(opts.patient.gender || "—")}</span>
        </td>
        <td style="width: 25%;">
          <span class="meta-label">Ordering Clinician</span>
          <span class="meta-val">${escapeHtml(opts.patient.clinician || "Attending Physician")}</span>
        </td>
      </tr>
      ${
        opts.patient.department || opts.patient.encounterNumber
          ? `
        <tr style="border-top: 1px solid #edf2f7;">
          <td colspan="2">
            <span class="meta-label">Department / Unit</span>
            <span class="meta-val">${escapeHtml(opts.patient.department || "General")}</span>
          </td>
          <td colspan="2">
            <span class="meta-label">Encounter Identifier</span>
            <span class="meta-val meta-mono">${escapeHtml(opts.patient.encounterNumber || "ENC-WALKIN")}</span>
          </td>
        </tr>
      `
          : ""
      }
    </table>

    <!-- Document Main Body -->
    ${opts.bodyHtml}

    <!-- Remarks / Impressions -->
    ${remarksHtml}

    <!-- Signatures -->
    ${signaturesHtml}

    <!-- Disclaimer -->
    <div class="disclaimer">
      ${escapeHtml(
        opts.footerNote ||
          "This clinical diagnostic report was authenticated and transmitted via the AfyaNova Hospital Information System (HIS). Valid without physical signature under Tanzania e-Health Guidelines.",
      )}
      ${
        opts.verificationHash
          ? `<br><span style="font-family: monospace; font-size: 8.5px;">Auth Hash: ${escapeHtml(opts.verificationHash)}</span>`
          : ""
      }
    </div>
  </div>
</body>
</html>
  `.trim();
}

/**
 * Send a compiled clinical document to the printer.
 *
 * Delegates to the shared delivery, which prints from a hidden iframe. The
 * popup this used to open was blockable, flashed on screen, and printed its own
 * `about:blank` address in the footer of every report.
 */
export function executeClinicalPrint(documentHtml: string): void {
  void printHtmlDocument(documentHtml);
}
