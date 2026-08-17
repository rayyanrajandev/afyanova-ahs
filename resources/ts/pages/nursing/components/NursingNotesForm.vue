/**
 * NursingNotesForm — Nursing Document & Clinical Note Upload (Volume 2.3 §10)
 * =========================================================================
 * 2027 Modern Enterprise Clinical Workstation Edition:
 * - High-Density Document Uploader Card
 * - Structured Document Type Selector
 * - Styled Drag/Browse File Container with format badges (.pdf, .jpg, .docx)
 * - Clear action bar with cancel and save triggers
 */

<script setup lang="ts">
import {
  FileText,
  Paperclip,
  UploadCloud,
  X,
} from "lucide-vue-next";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import type { UseNursingNotes } from "@/pages/nursing/composables/useNursingNotes";

/* eslint-disable vue/no-mutating-props -- v-model on the passed-in composable's form refs */

const props = defineProps<{
  notes: UseNursingNotes;
}>();

const emit = defineEmits<{
  cancel: [];
}>();

const { t } = useI18n();
const noteFileInput = ref<HTMLInputElement | null>(null);

function onFileChange(event: Event) {
  props.notes.onNoteFileChange(event);
}

async function save() {
  const saved = await props.notes.saveNote();
  if (saved && noteFileInput.value) noteFileInput.value.value = "";
}
</script>

<template>
  <div class="flex flex-1 flex-col overflow-hidden bg-background">
    <!-- Header -->
    <header class="flex shrink-0 items-center justify-between border-b border-border bg-surface px-4 py-2">
      <div class="flex items-center gap-2">
        <div class="flex size-7 items-center justify-center rounded-md bg-primary/10 text-primary">
          <FileText class="size-4" aria-hidden="true" />
        </div>
        <div>
          <h3 class="text-xs font-bold tracking-tight text-foreground flex items-center gap-1.5">
            <span>{{ t("nursing.new_note", "Upload Clinical Document") }}</span>
            <Badge variant="outline" class="text-[9px] font-mono px-1 py-0 uppercase">Notes</Badge>
          </h3>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <Button
          variant="ghost"
          size="sm"
          class="h-6.5 text-[11px] px-2 text-muted-foreground hover:text-foreground cursor-pointer"
          @click="emit('cancel')"
        >
          <X class="size-3 mr-1" />
          {{ t("common.cancel") }}
        </Button>
      </div>
    </header>

    <!-- Canvas -->
    <div class="flex-1 overflow-y-auto p-3.5 space-y-3 max-w-2xl">
      <section class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-3 text-xs">
        <!-- Document Type & Title -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="space-y-1">
            <Label required class="text-xs font-semibold text-foreground">
              {{ t("nursing.document_type") }}
            </Label>
            <Select v-model="notes.noteForm.value.documentType">
              <SelectTrigger class="h-8 text-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="type in notes.NOTE_DOCUMENT_TYPES" :key="type" :value="type">
                  {{ t(`nursing.document_type_${type}`) }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div class="space-y-1">
            <Label required class="text-xs font-semibold text-foreground">
              {{ t("nursing.document_title") }}
            </Label>
            <Input
              v-model="notes.noteForm.value.title"
              class="h-8 text-xs"
              :placeholder="t('nursing.document_title_placeholder')"
            />
          </div>
        </div>

        <!-- Description -->
        <div class="space-y-1">
          <Label class="text-xs font-semibold text-foreground">
            {{ t("nursing.document_description") }}
          </Label>
          <Textarea
            v-model="notes.noteForm.value.description"
            rows="2"
            class="text-xs resize-none"
            :placeholder="t('nursing.document_description_placeholder')"
          />
        </div>

        <!-- File Upload Area -->
        <div class="space-y-1">
          <Label required class="text-xs font-semibold text-foreground">
            {{ t("nursing.document_file") }}
          </Label>
          <div class="rounded-lg border border-dashed border-border/80 bg-muted/20 p-3 text-center">
            <input
              ref="noteFileInput"
              type="file"
              accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.txt"
              class="block w-full text-xs text-foreground file:mr-3 file:rounded-md file:border file:border-border file:bg-secondary file:px-2.5 file:py-1 file:text-xs file:font-semibold cursor-pointer"
              @change="onFileChange"
            />
            <p class="text-[10px] text-muted-foreground mt-1.5 font-mono">
              Accepted: PDF, JPG, PNG, DOCX, TXT (Max: 10MB)
            </p>
          </div>
        </div>
      </section>
    </div>

    <!-- Footer -->
    <footer class="flex shrink-0 items-center justify-end gap-2 border-t border-border bg-surface px-3.5 py-1.5">
      <Button
        variant="secondary"
        size="sm"
        class="h-7 text-xs cursor-pointer"
        @click="emit('cancel')"
      >
        {{ t("common.cancel") }}
      </Button>

      <Button
        size="sm"
        class="h-7 text-xs font-semibold gap-1 px-3.5 cursor-pointer shadow-xs"
        :disabled="notes.isSaving.value || !notes.noteForm.value.title.trim() || !notes.noteForm.value.file"
        @click="save"
      >
        <UploadCloud class="size-3" />
        <span>{{ notes.isSaving.value ? t("common.saving", "Uploading...") : t("common.save", "Upload Note") }}</span>
      </Button>
    </footer>
  </div>
</template>
