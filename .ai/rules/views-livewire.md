---
paths:
  - 'resources/views/livewire/**'
---

# Views Livewire

## Guard temporaryUrl() with isPreviewable() on upload previews
Livewire's `TemporaryUploadedFile::temporaryUrl()` throws FileNotPreviewableException for any type the browser can't render (PDF, zip, ...) — see livewire.temporary_file_upload.preview_mimes. The preview renders on the same request as the upload, before validation rejects the file, so `@if ($file)` alone 500s the page.

Always write `@if ($file && $file->isPreviewable())` before calling temporaryUrl(), and keep a non-image fallback in the else branch. Applies to the company logo and both avatar forms today.
