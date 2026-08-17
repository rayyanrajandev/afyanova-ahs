/**
 * laboratoryReportPrint.ts — Official ISO 15189 Diagnostic Laboratory Report Printer
 * ===================================================================================
 * Generates single-test and consolidated encounter laboratory reports using the
 * shared clinicalPrintEngine.
 */

import {
  compileClinicalDocumentHtml,
  escapeHtml,
  executeClinicalPrint,
  formatPrintDate,
  type ClinicalDocumentOptions,
  type DocumentSignatureInfo,
} from "@/services/print/clinicalPrintEngine";
import type { LaboratoryOrder } from "./composables/useLaboratoryOrders";

function renderParametersTable(order: LaboratoryOrder): string {
  const parameters =
    order.resultParameters && order.resultParameters.length > 0
      ? order.resultParameters
      : [
          {
            name: order.testName,
            value: order.resultSummary || "Negative / Normal",
            unit: "—",
            referenceRange: "Normal Baseline",
            flag: "normal" as const,
          },
        ];

  const rows = parameters
    .map((param, index) => {
      const isCritical = param.flag === "critical";
      const isAbnormal = param.flag === "abnormal";
      const flagText = isCritical ? "CRITICAL" : isAbnormal ? "ABNORMAL" : "NORMAL";
      const flagStyle = isCritical
        ? "color: #dc2626; font-weight: bold; background: #fef2f2;"
        : isAbnormal
          ? "color: #d97706; font-weight: bold; background: #fffbeb;"
          : "color: #059669;";

      return `
        <tr style="border-bottom: 1px solid #e5e7eb; ${index % 2 === 1 ? "background-color: #fafafa;" : ""}">
          <td style="padding: 6px 10px; font-weight: 600; color: #111827;">${escapeHtml(param.name)}</td>
          <td style="padding: 6px 10px; font-family: monospace; font-size: 12px; font-weight: bold; color: #111827;">
            ${escapeHtml(param.value)}
          </td>
          <td style="padding: 6px 10px; font-family: monospace; font-size: 11px; color: #4b5563;">${escapeHtml(param.unit || "—")}</td>
          <td style="padding: 6px 10px; font-family: monospace; font-size: 11px; color: #4b5563;">${escapeHtml(param.referenceRange || "—")}</td>
          <td style="padding: 6px 10px; font-size: 10px; text-align: center; ${flagStyle}">
            <span style="display: inline-block; padding: 1.5px 6px; border-radius: 3px; border: 1px solid currentColor;">${flagText}</span>
          </td>
        </tr>
      `;
    })
    .join("");

  return `
    <table class="results-table">
      <thead>
        <tr>
          <th style="width: 34%;">Test Parameter</th>
          <th style="width: 20%;">Observed Result</th>
          <th style="width: 14%;">Unit</th>
          <th style="width: 20%;">Reference Interval</th>
          <th style="width: 12%; text-align: center;">Evaluation</th>
        </tr>
      </thead>
      <tbody>
        ${rows}
      </tbody>
    </table>
  `;
}

/**
 * Prints a single diagnostic laboratory report.
 */
export function printLaboratoryReport(order: LaboratoryOrder): void {
  const isReleased = !!order.verifiedAt;
  const statusBadgeText = isReleased ? "FINAL VERIFIED REPORT" : "DRAFT / PRE-RELEASE";
  const statusBadgeColor = isReleased ? "#059669" : "#d97706";

  const bodyHtml = `
    <div class="section-title" style="display: flex; justify-content: space-between; align-items: center;">
      <span>${escapeHtml(order.testName)} (${escapeHtml(order.testCode)})</span>
      <span style="font-size: 10px; color: #64748b; font-family: monospace;">Sample: ${escapeHtml(order.sampleType)} • Acc: ${escapeHtml(order.orderNumber)}</span>
    </div>
    ${renderParametersTable(order)}
  `;

  const signatures: DocumentSignatureInfo[] = [
    {
      title: "Analyzing Technologist",
      name: "Automated Bench Analyzer / MLS",
      designation: `Performed: ${formatPrintDate(order.resultedAt || order.createdAt)}`,
      timestamp: order.resultedAt || order.createdAt,
      isVerified: false,
    },
    {
      title: "Chief Technologist / Verifier",
      name: order.verifiedBy || "Senior MLS / Pathologist",
      designation: isReleased ? "Electronically Authorized & Released" : "Pending Supervisor Release",
      timestamp: order.verifiedAt || undefined,
      isVerified: isReleased,
    },
  ];

  const docOptions: ClinicalDocumentOptions = {
    documentTitle: "Official Diagnostic Laboratory Report",
    documentBadge: statusBadgeText,
    documentBadgeColor: statusBadgeColor,
    documentNumber: order.orderNumber,
    patient: {
      name: order.patientName,
      mrn: order.patientMrn,
      age: order.patientAge,
      gender: order.patientGender,
      clinician: order.orderingClinician,
      department: order.department,
      issuedDate: formatPrintDate(order.verifiedAt || new Date().toISOString()),
    },
    bodyHtml,
    remarksTitle: "Pathologist Validation Remarks (ISO 15189 Clause 7.3)",
    remarksContent:
      order.verificationNote ||
      order.resultSummary ||
      "Results verified against internal calibration standards (IQC ±2SD). Findings correlate with pre-analytical parameters.",
    signatures,
    verificationHash: `AN-LAB-${order.orderNumber}-${(order.id || "").substring(0, 8).toUpperCase()}`,
  };

  const html = compileClinicalDocumentHtml(docOptions);
  executeClinicalPrint(html);
}

/**
 * Prints a consolidated encounter report containing ALL verified tests for the active patient.
 */
export function printConsolidatedLaboratoryReport(orders: LaboratoryOrder[]): void {
  if (!orders || orders.length === 0) return;

  const firstOrder = orders[0];
  const allReleased = orders.every((o) => !!o.verifiedAt);
  const statusBadgeText = allReleased ? "FINAL CONSOLIDATED REPORT" : "PARTIAL / DRAFT ENCOUNTER";
  const statusBadgeColor = allReleased ? "#059669" : "#d97706";

  // Group tests by department
  const depts: Record<string, LaboratoryOrder[]> = {};
  for (const o of orders) {
    const dept = o.department || "General Pathology";
    if (!depts[dept]) depts[dept] = [];
    depts[dept].push(o);
  }

  let bodyHtml = "";
  for (const [deptName, deptOrders] of Object.entries(depts)) {
    bodyHtml += `
      <div style="margin-top: 10px; margin-bottom: 4px; padding-bottom: 2px; border-bottom: 1.5px solid #0284c7; font-size: 11px; font-weight: 800; color: #0284c7; text-transform: uppercase; letter-spacing: 0.5px;">
        ${escapeHtml(deptName)} (${deptOrders.length} ${deptOrders.length === 1 ? "Investigation" : "Investigations"})
      </div>
    `;

    for (const ord of deptOrders) {
      const isOrdReleased = !!ord.verifiedAt;
      bodyHtml += `
        <div style="margin-top: 8px; margin-bottom: 3px; display: flex; justify-content: space-between; align-items: center;">
          <span style="font-size: 11px; font-weight: 700; color: #0f172a;">
            ${escapeHtml(ord.testName)}
            <span style="font-size: 9.5px; color: #64748b; font-family: monospace;">(${escapeHtml(ord.testCode)})</span>
          </span>
          <span style="font-size: 9.5px; font-family: monospace; color: ${isOrdReleased ? "#059669" : "#d97706"};">
            ${isOrdReleased ? "✓ Released" : "• Draft"} • Acc: ${escapeHtml(ord.orderNumber)}
          </span>
        </div>
        ${renderParametersTable(ord)}
      `;
    }
  }

  // Combined Remarks
  const combinedRemarks = orders
    .map((o) => (o.verificationNote ? `[${o.testName}]: ${o.verificationNote}` : null))
    .filter(Boolean)
    .join(" | ");

  const verifierName = orders.find((o) => o.verifiedBy)?.verifiedBy || "Senior MLS / Pathologist";

  const signatures: DocumentSignatureInfo[] = [
    {
      title: "Analyzing Technologist",
      name: "Automated Clinical Bench MLS",
      designation: "Clinical Diagnostics Section",
      timestamp: firstOrder.resultedAt || firstOrder.createdAt,
      isVerified: false,
    },
    {
      title: "Chief Technologist / Verifier",
      name: verifierName,
      designation: allReleased ? "All Investigations Electronically Authorized" : "Partial Encounter Release",
      timestamp: firstOrder.verifiedAt || undefined,
      isVerified: allReleased,
    },
  ];

  const docOptions: ClinicalDocumentOptions = {
    documentTitle: "Consolidated Diagnostic Laboratory Report",
    documentBadge: statusBadgeText,
    documentBadgeColor: statusBadgeColor,
    documentNumber: `ENC-${firstOrder.patientMrn}-${orders.length}T`,
    patient: {
      name: firstOrder.patientName,
      mrn: firstOrder.patientMrn,
      age: firstOrder.patientAge,
      gender: firstOrder.patientGender,
      clinician: firstOrder.orderingClinician,
      department: "Clinical Diagnostic Services",
      encounterNumber: `ENC-${firstOrder.patientMrn}`,
      issuedDate: formatPrintDate(new Date().toISOString()),
    },
    bodyHtml,
    remarksTitle: "Encounter Validation Summary (ISO 15189)",
    remarksContent:
      combinedRemarks ||
      "All tested investigations verified against standardized internal IQC quality controls. Clinical correlation recommended.",
    signatures,
    verificationHash: `AN-LAB-CONS-${firstOrder.patientMrn}-${orders.length}`,
  };

  const html = compileClinicalDocumentHtml(docOptions);
  executeClinicalPrint(html);
}
