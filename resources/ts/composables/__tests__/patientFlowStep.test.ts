/**
 * The shared step -> badge mapping now drives both the reception and clinician
 * queues, so it is the one place a wrong answer shows up on two screens.
 *
 * The bug it was extracted to fix: reception labelled every row from the tab
 * the user was on, not the row's own stage — so a patient a nurse had picked
 * up still read "Waiting Doctor" on the Waiting Doctor tab.
 */

import { describe, expect, it } from 'vitest';
import { isActiveContactStep, stepBadgeStatus, stepLabelKey } from '../patientFlowStep';

describe('patientFlowStep — labels', () => {
    it('labels the steps the flow ticket cares about', () => {
        expect(stepLabelKey('with_nurse')).toBe('patient.stage_with_nurse');
        expect(stepLabelKey('with_clinician')).toBe('patient.stage_with_clinician');
        expect(stepLabelKey('in_triage')).toBe('patient.stage_in_triage');
        expect(stepLabelKey('waiting_clinician')).toBe('patient.stage_waiting_clinician');
    });

    it('distinguishes waiting for triage from being in triage', () => {
        // These two shared one label before, so the badge could not tell a
        // patient sitting in the waiting area from one a nurse was triaging.
        expect(stepLabelKey('waiting_triage')).not.toBe(stepLabelKey('in_triage'));
    });

    it('returns null for an unknown or absent step so callers can fall back', () => {
        expect(stepLabelKey(null)).toBeNull();
        expect(stepLabelKey(undefined)).toBeNull();
        expect(stepLabelKey('something_new_from_the_backend')).toBeNull();
    });
});

describe('patientFlowStep — badge variants', () => {
    it('colours "somebody is with the patient" differently from "waiting"', () => {
        expect(stepBadgeStatus('with_nurse')).toBe('in_progress');
        expect(stepBadgeStatus('in_triage')).toBe('in_progress');
        expect(stepBadgeStatus('with_clinician')).toBe('info');

        expect(stepBadgeStatus('waiting_triage')).toBe('warning');
        expect(stepBadgeStatus('waiting_clinician')).toBe('warning');
        expect(stepBadgeStatus('waiting_clinician_review')).toBe('warning');
    });

    it('marks terminal steps distinctly', () => {
        expect(stepBadgeStatus('completed')).toBe('complete');
        expect(stepBadgeStatus('cancelled')).toBe('cancelled');
        expect(stepBadgeStatus('admitted')).toBe('success');
    });
});

describe('patientFlowStep — active contact', () => {
    it('agrees with the backend about which steps mean active contact', () => {
        // Mirrors PatientFlowStep::isActiveContact().
        for (const step of ['with_nurse', 'with_clinician', 'in_triage', 'in_lab', 'in_direct_service']) {
            expect(isActiveContactStep(step)).toBe(true);
        }

        for (const step of ['waiting_triage', 'waiting_clinician', 'waiting_pharmacy', 'completed']) {
            expect(isActiveContactStep(step)).toBe(false);
        }
    });

    it('treats an unknown step as not active rather than guessing', () => {
        expect(isActiveContactStep(null)).toBe(false);
        expect(isActiveContactStep('brand_new_step')).toBe(false);
    });
});

describe('patientFlowStep — diagnostic step labels (laboratory flow plan, phase 4)', () => {
    it('labels every diagnostic step it already colours', () => {
        // These six had badge colours but no label keys, so a row for a patient
        // standing in the lab or in imaging fell through to a generic badge —
        // the workspace could colour the row correctly while being unable to
        // say what it meant.
        const diagnosticSteps = [
            'waiting_lab',
            'in_lab',
            'waiting_imaging',
            'in_imaging',
            'waiting_lab_and_imaging',
            'in_lab_and_imaging',
        ];

        for (const step of diagnosticSteps) {
            expect(stepLabelKey(step), `${step} has no label key`).not.toBeNull();
            expect(stepBadgeStatus(step), `${step} has no badge status`).not.toBeNull();
        }
    });

    it('keeps waiting and in-progress diagnostic steps visually distinct', () => {
        expect(stepBadgeStatus('waiting_lab')).toBe('warning');
        expect(stepBadgeStatus('in_lab')).toBe('in_progress');
        expect(stepBadgeStatus('waiting_imaging')).toBe('warning');
        expect(stepBadgeStatus('in_imaging')).toBe('in_progress');
    });

    it('gives each diagnostic step its own label rather than reusing one', () => {
        const keys = [
            stepLabelKey('waiting_lab'),
            stepLabelKey('in_lab'),
            stepLabelKey('waiting_imaging'),
            stepLabelKey('in_imaging'),
            stepLabelKey('waiting_lab_and_imaging'),
            stepLabelKey('in_lab_and_imaging'),
        ];

        // Lab and imaging are physically different departments; a shared label
        // would put a patient in the wrong one.
        expect(new Set(keys).size).toBe(keys.length);
    });
});
