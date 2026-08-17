<script setup lang="ts">
import { Check, Edit2, MessageSquare, Send, Trash2, User, X } from "lucide-vue-next";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Textarea } from "@/components/ui/textarea";

const props = defineProps<{
  open: boolean;
  appointmentId: string | null;
  verificationNotes?: string | null;
}>();

const emit = defineEmits<{
  "update:open": [value: boolean];
  noteAdded: [newNotes: string];
}>();

const { t } = useI18n();

const isOpen = computed({
  get: () => props.open,
  set: (val) => emit("update:open", val),
});

const newNoteText = ref("");
const isSubmitting = ref(false);
const isLoading = ref(false);
const localNotes = ref<string>("");

// Editing state
const editingIndex = ref<number | null>(null);
const editingText = ref<string>("");

async function fetchNotes() {
  if (!props.appointmentId) return;
  isLoading.value = true;
  try {
    const res = await fetch(`/api/v1/nursing/visit-notes/${props.appointmentId}`, {
      headers: { "X-Requested-With": "XMLHttpRequest" },
    });
    if (res.ok) {
      const body = (await res.json()) as { data?: { verificationNotes?: string } };
      localNotes.value = body.data?.verificationNotes ?? "";
    }
  } catch (e) {
    console.error(e);
  } finally {
    isLoading.value = false;
  }
}

watch(
  [() => props.open, () => props.appointmentId],
  ([open, aptId]) => {
    if (open && aptId) {
      editingIndex.value = null;
      void fetchNotes();
    } else if (props.verificationNotes !== undefined) {
      localNotes.value = props.verificationNotes ?? "";
    }
  },
  { immediate: true }
);

const noteLines = computed(() => {
  if (!localNotes.value.trim()) return [];
  return localNotes.value
    .split("\n")
    .map((line) => line.trim())
    .filter(Boolean);
});

async function submitNote() {
  if (!newNoteText.value.trim() || !props.appointmentId) return;

  isSubmitting.value = true;
  try {
    const res = await fetch(`/api/v1/nursing/visit-notes/${props.appointmentId}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
      body: JSON.stringify({ note: newNoteText.value.trim() }),
    });

    if (!res.ok) throw new Error("Failed to post visit note");

    const body = (await res.json()) as { data?: { verificationNotes?: string } };
    const updated = body.data?.verificationNotes ?? localNotes.value;
    localNotes.value = updated;
    emit("noteAdded", updated);
    newNoteText.value = "";
  } catch (e) {
    console.error(e);
  } finally {
    isSubmitting.value = false;
  }
}

function startEditing(index: number, line: string) {
  editingIndex.value = index;
  editingText.value = line;
}

function cancelEditing() {
  editingIndex.value = null;
  editingText.value = "";
}

async function saveEditing(index: number) {
  if (!props.appointmentId || editingText.value.trim() === "") return;

  const lines = [...noteLines.value];
  lines[index] = editingText.value.trim();
  const updatedFull = lines.join("\n");

  isSubmitting.value = true;
  try {
    const res = await fetch(`/api/v1/nursing/visit-notes/${props.appointmentId}`, {
      method: "PUT",
      headers: {
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
      body: JSON.stringify({ verificationNotes: updatedFull }),
    });

    if (res.ok) {
      localNotes.value = updatedFull;
      emit("noteAdded", updatedFull);
      editingIndex.value = null;
    }
  } catch (e) {
    console.error(e);
  } finally {
    isSubmitting.value = false;
  }
}

async function deleteNote(index: number) {
  if (!props.appointmentId) return;

  isSubmitting.value = true;
  try {
    const res = await fetch(`/api/v1/nursing/visit-notes/${props.appointmentId}`, {
      method: "DELETE",
      headers: {
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
      body: JSON.stringify({ index }),
    });

    if (res.ok) {
      const body = (await res.json()) as { data?: { verificationNotes?: string } };
      const updated = body.data?.verificationNotes ?? "";
      localNotes.value = updated;
      emit("noteAdded", updated);
    }
  } catch (e) {
    console.error(e);
  } finally {
    isSubmitting.value = false;
  }
}
</script>

<template>
  <Dialog v-model:open="isOpen">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          <MessageSquare class="h-5 w-5 text-primary" />
          Visit Communication Thread
        </DialogTitle>
        <DialogDescription>
          Shared notes between Reception and Nursing for this active visit.
        </DialogDescription>
      </DialogHeader>

      <!-- Thread messages container -->
      <div class="my-2 max-h-64 overflow-y-auto rounded-md border border-border bg-muted/30 p-3 space-y-2">
        <div v-if="isLoading" class="space-y-2 p-1 animate-pulse">
          <div class="h-10 rounded bg-secondary/80" />
          <div class="h-10 rounded bg-secondary/60" />
        </div>
        <div v-else-if="noteLines.length === 0" class="py-6 text-center text-xs text-muted-foreground">
          No communication notes recorded for this visit yet.
        </div>
        <div
          v-for="(line, idx) in noteLines"
          :key="idx"
          class="group relative rounded bg-background p-2.5 text-xs text-foreground shadow-sm border border-border/50 transition-colors hover:border-border"
        >
          <!-- Editing state -->
          <div v-if="editingIndex === idx" class="space-y-2">
            <Textarea v-model="editingText" rows="2" class="text-xs" />
            <div class="flex items-center justify-end gap-1.5">
              <Button size="sm" variant="ghost" class="h-7 text-xs" @click="cancelEditing">Cancel</Button>
              <Button size="sm" class="h-7 text-xs gap-1" :disabled="isSubmitting" @click="saveEditing(idx)">
                <Check class="h-3 w-3" /> Save
              </Button>
            </div>
          </div>

          <!-- Display state -->
          <div v-else class="flex items-start justify-between gap-2">
            <span class="whitespace-pre-wrap leading-relaxed flex-1 min-w-0 break-words">{{ line }}</span>
            <div class="flex items-center gap-1 opacity-80 group-hover:opacity-100 shrink-0">
              <button
                type="button"
                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                title="Edit this note"
                @click="startEditing(idx, line)"
              >
                <Edit2 class="h-3 w-3" />
              </button>
              <button
                type="button"
                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive transition-colors"
                title="Delete this note"
                @click="deleteNote(idx)"
              >
                <Trash2 class="h-3 w-3" />
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Add note input -->
      <div class="mt-2 space-y-2">
        <Textarea
          v-model="newNoteText"
          placeholder="Type a note for Reception / Nursing (e.g. 'Payment receipt verified by spouse')..."
          rows="2"
          class="text-xs"
        />
      </div>

      <DialogFooter class="mt-2 flex items-center justify-between sm:justify-between">
        <Button variant="ghost" size="sm" @click="isOpen = false">Close</Button>
        <Button
          size="sm"
          :disabled="!newNoteText.trim() || isSubmitting || !appointmentId"
          class="gap-1.5"
          @click="submitNote"
        >
          <Send class="h-3.5 w-3.5" />
          Post Note
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
