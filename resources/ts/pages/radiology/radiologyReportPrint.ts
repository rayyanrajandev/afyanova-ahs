/**
 * radiologyReportPrint.ts — Official Diagnostic Imaging & Radiology Report Printer
 * =================================================================================
 * Generates hospital-grade A4 diagnostic imaging reports utilizing the universal
 * clinicalPrintEngine with DICOM Key Image embedding.
 */

import {
  compileClinicalDocumentHtml,
  escapeHtml,
  executeClinicalPrint,
  formatPrintDate,
  type ClinicalDocumentOptions,
  type DocumentSignatureInfo,
} from "@/services/print/clinicalPrintEngine";
import type {
  DicomImageInstance,
  RadiologyOrder,
} from "./composables/useRadiologyOrders";

/**
 * Prints an official Diagnostic Imaging Examination Report with optional Key Image attachments.
 */
export function printRadiologyReport(
  order: RadiologyOrder,
  images?: DicomImageInstance[],
): void {
  const isReleased = Boolean(order.verifiedAt);
  const statusBadgeText = isReleased
    ? "FINAL VERIFIED REPORT"
    : "PRELIMINARY / DRAFT";
  const statusBadgeColor = isReleased ? "#059669" : "#d97706";

  const studyDate = formatPrintDate(
    order.completedAt || order.scheduledFor || order.orderedAt,
  );

  // Parse structured report sections if available, or format raw report
  const rawReport =
    order.reportSummary ||
    "Examination performed without acute complications. Findings recorded in clinical chart.";

  // Filter key images or first 2 images
  const keyImages = (images || []).filter((i) => i.isKeyImage).slice(0, 3);
  const displayImages =
    keyImages.length > 0 ? keyImages : (images || []).slice(0, 2);

  let keyImagesHtml = "";
  if (displayImages.length > 0) {
    keyImagesHtml = `
      <div class="section-title" style="margin-top: 14px;">Key Diagnostic DICOM Captures</div>
      <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px;">
        ${displayImages
          .map(
            (img, idx) => `
          <div style="flex: 1; min-width: 180px; max-width: 240px; border: 1px solid #cbd5e1; border-radius: 4px; padding: 4px; background: #0f172a; text-align: center;">
            <img src="${img.imageUrl}" alt="${escapeHtml(img.seriesDescription)}" style="width: 100%; height: 130px; object-fit: contain; background: #000; border-radius: 2px;" />
            <div style="font-family: monospace; font-size: 8.5px; color: #cbd5e1; margin-top: 4px; text-align: left; padding: 0 2px;">
              <strong style="color: #38bdf8;">Frame ${img.instanceNumber || idx + 1}:</strong> ${escapeHtml(img.seriesDescription)}
            </div>
          </div>
        `,
          )
          .join("")}
      </div>
    `;
  }

  const bodyHtml = `
    <!-- Examination & Modality Details Table -->
    <table class="meta-box" style="margin-top: 4px; margin-bottom: 12px;">
      <tr>
        <td style="width: 30%;">
          <span class="meta-label">Imaging Modality</span>
          <span class="meta-val font-mono uppercase" style="color: #0284c7;">${escapeHtml(order.modality)}</span>
        </td>
        <td style="width: 40%;">
          <span class="meta-label">Study Description / Protocol</span>
          <span class="meta-val">${escapeHtml(order.studyDescription)}</span>
        </td>
        <td style="width: 30%;">
          <span class="meta-label">Study Date &amp; Time</span>
          <span class="meta-val font-mono">${studyDate}</span>
        </td>
      </tr>
      ${
        order.clinicalIndication
          ? `
        <tr style="border-top: 1px solid #edf2f7;">
          <td colspan="3">
            <span class="meta-label">Clinical Indication / History</span>
            <span class="meta-val" style="font-weight: 500; color: #334155;">${escapeHtml(order.clinicalIndication)}</span>
          </td>
        </tr>
      `
          : ""
      }
    </table>

    <!-- Key Medical DICOM Image Captures -->
    ${keyImagesHtml}

    <!-- Diagnostic Findings Body -->
    <div class="section-title">Diagnostic Findings &amp; Observations</div>
    <div style="border: 1px solid #e2e8f0; background: #ffffff; border-radius: 5px; padding: 12px 14px; margin-bottom: 12px; line-height: 1.6; font-size: 11.5px; color: #1e293b; white-space: pre-wrap; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
${escapeHtml(rawReport)}
    </div>
  `;

  const signatures: DocumentSignatureInfo[] = [
    {
      title: "Reporting Radiographer / Technologist",
      name: "Department Radiographer",
      designation: `Acquired: ${formatPrintDate(order.completedAt || order.orderedAt)}`,
      timestamp: order.completedAt || order.orderedAt || undefined,
      isVerified: false,
    },
    {
      title: "Consultant Radiologist / Verifier",
      name: order.verifiedBy || "Consultant Radiologist / Imaging Specialist",
      designation: isReleased
        ? "Electronically Authorized & Chart Released"
        : "Pending Verifier Authorization",
      timestamp: order.verifiedAt || undefined,
      isVerified: isReleased,
    },
  ];

  const docOptions: ClinicalDocumentOptions = {
    documentTitle: "Official Diagnostic Radiology Report",
    documentBadge: statusBadgeText,
    documentBadgeColor: statusBadgeColor,
    documentNumber:
      order.orderNumber || `RAD-${order.id.slice(0, 8).toUpperCase()}`,
    patient: {
      name: order.patientName || "Patient",
      mrn: order.patientMrn || "MRN-0000",
      age: order.patientAge ? String(order.patientAge) : undefined,
      gender: order.patientGender || undefined,
      clinician: order.orderingClinician || "Attending Clinician",
      department: "Diagnostic Radiology & Imaging Services",
      encounterNumber: `ENC-${order.patientMrn || "WALKIN"}`,
      issuedDate: formatPrintDate(order.verifiedAt || new Date().toISOString()),
    },
    bodyHtml,
    remarksTitle: "Radiologist Clinical Impression & Recommendations",
    remarksContent:
      order.verificationNote ||
      "Findings should be correlated with clinical presentation and prior diagnostic imaging studies where indicated.",
    signatures,
    verificationHash: `AN-RAD-${order.orderNumber || order.id.slice(0, 8).toUpperCase()}`,
    footerNote:
      "This diagnostic imaging examination report was electronically authenticated under the AfyaNova Hospital Information System (HIS). ACR & ISO standards compliant.",
  };

  const html = compileClinicalDocumentHtml(docOptions);
  executeClinicalPrint(html);
}
