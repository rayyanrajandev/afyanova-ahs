/**
 * patientFromBackend — a missing id must not become the string "undefined".
 * =======================================================================
 * `String(row.id)` produced the literal "undefined" for a payload without an
 * id. That value is truthy, so it passed every `if (!patient.id)` guard and was
 * sent to the API as a real patient id — which is how "Save & Check In" could
 * register a patient and then fail the check-in half on a validation error
 * about the patient id, while the UI reported success.
 */

import { describe, expect, it } from 'vitest';
import { patientFromBackend } from '../patientStore';

describe('patientFromBackend — id mapping', () => {
    it('carries a real id through unchanged', () => {
        expect(patientFromBackend({ id: '01a00671-dd37-704d-af62-5620e51d8767' }).id).toBe(
            '01a00671-dd37-704d-af62-5620e51d8767',
        );
    });

    it('yields a falsy id when the payload has none, never "undefined"', () => {
        const patient = patientFromBackend({});

        expect(patient.id).not.toBe('undefined');
        expect(patient.id).toBeFalsy();
    });

    it('yields a falsy id when the payload id is explicitly null', () => {
        const patient = patientFromBackend({ id: null as unknown as string });

        expect(patient.id).not.toBe('null');
        expect(patient.id).toBeFalsy();
    });
});
