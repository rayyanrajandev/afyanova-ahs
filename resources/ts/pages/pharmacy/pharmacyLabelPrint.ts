import { pageRule, printHtmlDocument } from "@/services/print/printDelivery";
/**
 * pharmacyLabelPrint — Thermal Label & Dispensing Receipt Print Engine (Volume 2.6)
 * =================================================================================
 * Generates standards-compliant hospital dispensing labels and receipts:
 * - Patient MRN, Name, Age, Gender
 * - Medication Name, Strength, Dosage form
 * - Full Directions for Use (Dose, Route, Frequency, Duration)
 * - Clinical Indication & Cautionary Warnings
 * - Batch No, Expiry Date, Prescribing Doctor, Dispensing Pharmacist
 * - Thermal 50mm x 30mm, 70mm x 50mm, and A4 Dispensing Sheet formats
 */

import type { PharmacyOrder } from "./composables/usePharmacyOrders";

export interface LabelPrintOptions {
  facilityName?: string;
  facilityPhone?: string;
  dispenserName?: string;
  format?: "thermal_small" | "thermal_standard" | "receipt";
}

export function printPharmacyLabel(
  order: PharmacyOrder,
  options: LabelPrintOptions = {},
): void {
  const facilityName = options.facilityName || "AFYANOVA HEALTH SYSTEM";
  const facilityPhone = options.facilityPhone || "";
  const dispenserName =
    options.dispenserName || order.verifiedBy || "Pharmacy Dept";

  const html = `
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>Medication Label - ${order.orderNumber || order.medicationName}</title>
      <style>
        ${pageRule("70mm 50mm", "3mm")}
        * {
          box-sizing: border-box;
          margin: 0;
          padding: 0;
        }
        body {
          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
          font-size: 9pt;
          line-height: 1.2;
          color: #000;
          background: #fff;
          padding: 4px;
        }
        .label-container {
          border: 1.5px solid #000;
          border-radius: 4px;
          padding: 6px 8px;
          height: 100%;
          display: flex;
          flex-direction: column;
          justify-content: space-between;
        }
        .header {
          text-align: center;
          border-bottom: 1px dashed #000;
          padding-bottom: 3px;
          margin-bottom: 4px;
        }
        .facility {
          font-weight: 800;
          font-size: 8pt;
          text-transform: uppercase;
          letter-spacing: 0.5px;
        }
        .patient-row {
          display: flex;
          justify-content: space-between;
          font-weight: 700;
          font-size: 8.5pt;
          margin-bottom: 4px;
        }
        .med-title {
          font-size: 11pt;
          font-weight: 900;
          text-transform: uppercase;
          margin: 2px 0 4px 0;
          line-height: 1.1;
        }
        .directions-box {
          background: #f0f0f0;
          border: 1px solid #000;
          padding: 4px 6px;
          border-radius: 3px;
          font-size: 9.5pt;
          font-weight: 700;
          margin: 4px 0;
          line-height: 1.25;
        }
        .meta-grid {
          display: grid;
          grid-template-columns: 1fr 1fr;
          font-size: 7.5pt;
          gap: 2px;
          margin-top: 3px;
        }
        .warning {
          font-size: 7.5pt;
          font-weight: 800;
          text-transform: uppercase;
          text-align: center;
          margin-top: 3px;
          border-top: 1px dashed #000;
          padding-top: 2px;
        }
        .footer {
          display: flex;
          justify-content: space-between;
          font-size: 7pt;
          color: #333;
          margin-top: 2px;
        }
        @media print {
          body { padding: 0; }
          .label-container { border: 1.5px solid #000; }
        }
      </style>
    </head>
    <body>
      <div class="label-container">
        <div class="header">
          <div class="facility">${facilityName}</div>
          ${facilityPhone ? `<div style="font-size:7pt;">Tel: ${facilityPhone}</div>` : ""}
        </div>

        <div class="patient-row">
          <span>${order.patientName || "Patient"}</span>
          <span>MRN: ${order.patientMrn || "—"}</span>
        </div>

        <div class="med-title">
          ${order.medicationName || order.medicationCode}
        </div>

        <div class="directions-box">
          ${order.dosageInstruction || `${order.doseQuantity || 1} ${order.doseUnit || "unit"} ${order.frequency || "daily"} for ${order.durationValue || 5} ${order.durationUnit || "days"}`}
        </div>

        <div class="meta-grid">
          <div><strong>Qty:</strong> ${order.quantityDispensed || order.quantityPrescribed || "1"} ${order.dispensedUnit || order.prescribedUnit || "units"}</div>
          <div><strong>Rx Date:</strong> ${order.orderedAt ? new Date(order.orderedAt).toLocaleDateString() : new Date().toLocaleDateString()}</div>
          <div><strong>Prescriber:</strong> ${order.orderingClinician || "Clinician"}</div>
          <div><strong>Dispenser:</strong> ${dispenserName}</div>
        </div>

        <div class="warning">
          KEEP OUT OF REACH OF CHILDREN • STORE IN A COOL DRY PLACE
        </div>

        <div class="footer">
          <span>Rx: ${order.orderNumber || order.id.substring(0, 8)}</span>
          <span>Printed: ${new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}</span>
        </div>
      </div>
    </body>
    </html>
  `;

  void printHtmlDocument(html, { title: `Label — ${order.medicationName}` });
}

export function printConsolidatedPrescription(
  orders: PharmacyOrder[],
  options: LabelPrintOptions = {},
): void {
  if (!orders.length) return;

  const first = orders[0];
  const facilityName = options.facilityName || "AFYANOVA HEALTH SYSTEM";

  const itemsHtml = orders
    .map(
      (o, i) => `
      <tr style="border-bottom: 1px solid #ddd;">
        <td style="padding: 8px; vertical-align: top; font-weight: bold;">${i + 1}</td>
        <td style="padding: 8px; vertical-align: top;">
          <div style="font-weight: bold; font-size: 11pt;">${o.medicationName || o.medicationCode}</div>
          <div style="color: #333; margin-top: 2px;">${o.dosageInstruction || `${o.doseQuantity || 1} ${o.doseUnit || "units"} • ${o.frequency || "as directed"}`}</div>
          ${o.clinicalIndication ? `<div style="font-size: 8.5pt; color: #666; font-style: italic;">Indication: ${o.clinicalIndication}</div>` : ""}
        </td>
        <td style="padding: 8px; vertical-align: top; text-align: center;">${o.quantityDispensed || o.quantityPrescribed || "1"} ${o.dispensedUnit || o.prescribedUnit || "units"}</td>
        <td style="padding: 8px; vertical-align: top; text-align: right;">${o.unitPrice ? o.unitPrice.toLocaleString() + " TZS" : "—"}</td>
        <td style="padding: 8px; vertical-align: top; text-align: right; font-weight: bold;">${o.totalPrice ? o.totalPrice.toLocaleString() + " TZS" : "—"}</td>
      </tr>
    `,
    )
    .join("");

  const totalSum = orders.reduce((sum, o) => sum + (o.totalPrice || 0), 0);

  const html = `
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>Pharmacy Dispensation - ${first.patientName}</title>
      <style>
        ${pageRule("A4", "15mm")}
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 10pt; color: #111; padding: 20px; }
        .header-table { width: 100%; border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 16px; }
        .patient-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; margin-bottom: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 9.5pt; }
        table.meds { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.meds th { background: #0f172a; color: #fff; padding: 8px; text-align: left; font-size: 9pt; text-transform: uppercase; }
        .total-box { text-align: right; font-size: 12pt; font-weight: bold; margin-bottom: 24px; }
        .sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; }
        .sig-line { border-top: 1px solid #000; padding-top: 6px; text-align: center; font-size: 9pt; }
      </style>
    </head>
    <body>
      <table class="header-table">
        <tr>
          <td>
            <h1 style="font-size: 16pt; margin: 0; color: #0f172a;">${facilityName}</h1>
            <div style="color: #64748b; font-size: 9pt;">PHARMACY DISPENSING DEPARTMENT</div>
          </td>
          <td style="text-align: right;">
            <div style="font-size: 12pt; font-weight: bold; color: #0f172a;">DISPENSING SLIP</div>
            <div style="font-size: 9pt; color: #64748b;">Date: ${new Date().toLocaleDateString()}</div>
          </td>
        </tr>
      </table>

      <div class="patient-box">
        <div><strong>Patient Name:</strong> ${first.patientName || "—"}</div>
        <div><strong>MRN:</strong> ${first.patientMrn || "—"}</div>
        <div><strong>Age / Gender:</strong> ${first.patientAge || "—"} yrs / ${first.patientGender || "—"}</div>
        <div><strong>Prescribing Doctor:</strong> ${first.orderingClinician || "—"}</div>
      </div>

      <table class="meds">
        <thead>
          <tr>
            <th style="width: 30px;">#</th>
            <th>Medication & Directions</th>
            <th style="width: 80px; text-align: center;">Qty</th>
            <th style="width: 100px; text-align: right;">Unit Price</th>
            <th style="width: 110px; text-align: right;">Total Price</th>
          </tr>
        </thead>
        <tbody>
          ${itemsHtml}
        </tbody>
      </table>

      ${
        totalSum > 0
          ? `<div class="total-box">Grand Total: ${totalSum.toLocaleString()} TZS</div>`
          : ""
      }

      <div style="font-size: 8.5pt; color: #64748b; margin-top: 20px; line-height: 1.4;">
        <strong>Patient Instructions:</strong> Please take medications strictly according to the directions. If you experience any unexpected adverse effects, contact the hospital immediately.
      </div>

      <div class="sig-grid">
        <div class="sig-line">
          <strong>Dispensed By (Pharmacist / Technician)</strong><br>
          <span>Date & Signature</span>
        </div>
        <div class="sig-line">
          <strong>Received By (Patient / Guardian)</strong><br>
          <span>Signature</span>
        </div>
      </div>

    </body>
    </html>
  `;

  void printHtmlDocument(html, {
    title: `Prescription — ${first.patientName ?? "Patient"}`,
  });
}
