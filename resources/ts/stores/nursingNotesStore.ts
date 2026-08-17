/**
 * Nursing Notes Store (Volume 2.3 §10, Volume 3.8 Phase 4)
 * ============================================================
 * Uploads a clinical document to a patient's encounter — this is a file
 * attachment (title + document type + file), not a free-text SBAR note.
 * Confirmed by reading `EncounterClinicalAttachmentController::store()`
 * and `StoreEncounterClinicalDocumentRequest` directly (2026-08-13) before
 * building this — the route also had a real backend bug (missing `{id}`
 * URI segment, 500 on every call) fixed the same day.
 *
 * API endpoint: POST /api/v1/nursing/notes/{encounterId} (multipart/form-data)
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

export interface NursingNoteInput {
    documentType: string;
    title: string;
    description?: string;
    file: File;
}

export const useNursingNotesStore = defineStore('nursingNotes', () => {
    const isSaving = ref(false);
    const error = ref<string | null>(null);

    /** POST /nursing/notes/{encounterId} */
    async function uploadNote(encounterId: string, input: NursingNoteInput): Promise<boolean> {
        isSaving.value = true;
        error.value = null;
        try {
            const form = new FormData();
            form.append('documentType', input.documentType);
            form.append('title', input.title);
            if (input.description) form.append('description', input.description);
            form.append('file', input.file);

            const res = await fetch(`/api/v1/nursing/notes/${encodeURIComponent(encounterId)}`, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: form,
            });
            if (!res.ok) throw new Error('Failed to upload note');
            return true;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to upload note';
            return false;
        } finally {
            isSaving.value = false;
        }
    }

    return {
        isSaving,
        error,
        uploadNote,
    };
});
