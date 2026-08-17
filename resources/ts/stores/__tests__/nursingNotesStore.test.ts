/**
 * nursingNotesStore — `uploadNote()` (Volume 2.3 §10, Volume 3.8 Phase 4).
 * =======================================================================
 * Covers the multipart FormData contract directly — this is a file
 * upload, not a JSON POST (Phase 4 deliberately redesigned the form
 * around what `EncounterClinicalAttachmentController::store()` actually
 * expects), so the test asserts the request has no `Content-Type` header
 * set manually (the browser must set the multipart boundary itself) and
 * that the file/title/documentType land in the FormData.
 */

import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useNursingNotesStore, type NursingNoteInput } from '../nursingNotesStore';

function input(overrides: Partial<NursingNoteInput> = {}): NursingNoteInput {
    return {
        documentType: 'nursing_note',
        title: 'Shift handover note',
        file: new File(['test content'], 'note.pdf', { type: 'application/pdf' }),
        ...overrides,
    };
}

describe('nursingNotesStore — uploadNote', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('posts a multipart FormData body to the encounter-scoped URL without a manual Content-Type', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        vi.stubGlobal('fetch', fetchMock);

        const store = useNursingNotesStore();
        const ok = await store.uploadNote('enc-1', input());

        expect(ok).toBe(true);
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/v1/nursing/notes/enc-1');
        expect(options.method).toBe('POST');
        expect(options.body).toBeInstanceOf(FormData);
        expect(options.headers).not.toHaveProperty('Content-Type');

        const form = options.body as FormData;
        expect(form.get('documentType')).toBe('nursing_note');
        expect(form.get('title')).toBe('Shift handover note');
        expect(form.get('file')).toBeInstanceOf(File);
    });

    it('includes an optional description only when provided', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        vi.stubGlobal('fetch', fetchMock);

        const store = useNursingNotesStore();
        await store.uploadNote('enc-1', input({ description: 'Handover at shift change' }));

        const form = fetchMock.mock.calls[0][1].body as FormData;
        expect(form.get('description')).toBe('Handover at shift change');
    });

    it('omits description entirely when not provided, rather than sending an empty string', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        vi.stubGlobal('fetch', fetchMock);

        const store = useNursingNotesStore();
        await store.uploadNote('enc-1', input());

        const form = fetchMock.mock.calls[0][1].body as FormData;
        expect(form.has('description')).toBe(false);
    });

    it('returns false and sets error on a non-ok response', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, json: async () => ({}) }));

        const store = useNursingNotesStore();
        const ok = await store.uploadNote('enc-1', input());

        expect(ok).toBe(false);
        expect(store.error).toBe('Failed to upload note');
    });
});
