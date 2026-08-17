/**
 * Nursing notes (Volume 2.3 §10, Volume 3.8 Phase 4)
 * =========================================================================
 * Extracted from nursing/Index.vue (2026-08-13, component decomposition —
 * Reception-style separation of concerns). "Notes" here uploads a clinical
 * document (file + type + title) to the encounter — not a free-text SBAR
 * form — matching what `POST /nursing/notes/{encounterId}` actually does
 * (see `EncounterClinicalAttachmentController::store()`).
 */

import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";
import { useNursingNotesStore } from "@/stores/nursingNotesStore";

const NOTE_DOCUMENT_TYPES = ["nursing_note", "shift_handover", "incident_report", "care_plan", "other"] as const;
type NoteDocumentType = (typeof NOTE_DOCUMENT_TYPES)[number];

export interface UseNursingNotesOptions {
  /** Active encounter id, or null when there is no open encounter. */
  encounterId: () => string | null;
  /** Called after a successful upload so the caller can close the form. */
  onSaved?: () => void;
}

export function useNursingNotes(options: UseNursingNotesOptions) {
  const { t } = useI18n();
  const toast = useToast();
  const notesStore = useNursingNotesStore();

  const noteForm = ref<{ documentType: NoteDocumentType; title: string; description: string; file: File | null }>({
    documentType: "nursing_note",
    title: "",
    description: "",
    file: null,
  });

  function onNoteFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    noteForm.value.file = input.files?.[0] ?? null;
  }

  async function saveNote(): Promise<boolean> {
    const encounterId = options.encounterId();
    if (!encounterId) return false;
    if (!noteForm.value.title.trim() || !noteForm.value.file) return false;
    const ok = await notesStore.uploadNote(encounterId, {
      documentType: noteForm.value.documentType,
      title: noteForm.value.title,
      description: noteForm.value.description || undefined,
      file: noteForm.value.file,
    });
    if (!ok) {
      toast.critical(t("nursing.note_save_failed"));
      return false;
    }
    toast.success(t("nursing.note_saved"));
    noteForm.value = { documentType: "nursing_note", title: "", description: "", file: null };
    options.onSaved?.();
    return true;
  }

  return {
    NOTE_DOCUMENT_TYPES,
    noteForm,
    onNoteFileChange,
    saveNote,
    isSaving: computed(() => notesStore.isSaving),
  };
}

export type UseNursingNotes = ReturnType<typeof useNursingNotes>;
