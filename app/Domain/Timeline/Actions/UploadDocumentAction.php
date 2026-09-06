<?php

namespace App\Domain\Timeline\Actions;

use App\Domain\Timeline\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Attach a file to a record.
 *
 * The row and the file are written together: a document with no file is a
 * broken entry in the timeline, and a stored file with no row is an orphan
 * nobody can reach or delete.
 */
class UploadDocumentAction
{
    public function __invoke(
        Model $subject,
        User $uploader,
        UploadedFile $file,
        ?string $title = null,
        ?string $description = null,
    ): Document {
        return DB::transaction(function () use ($subject, $uploader, $file, $title, $description) {
            $document = new Document([
                'documentable_type' => $subject->getMorphClass(),
                'documentable_id' => $subject->getKey(),
                'uploaded_by_id' => $uploader->id,
                // The file's own name is the obvious title, and typing one out
                // again should be optional rather than required.
                'title' => trim((string) ($title ?: $file->getClientOriginalName())),
                'description' => $description === null || trim($description) === '' ? null : trim($description),
            ]);

            $document->save();

            $document->addMedia($file->getRealPath())
                ->usingFileName($this->safeFileName($file))
                ->usingName($document->title)
                ->toMediaCollection('file');

            $document->setRelation('uploadedBy', $uploader);

            return $document->refresh();
        });
    }

    /**
     * A stored name built from the original rather than trusted as it arrived:
     * the client name is attacker-controlled and has no business deciding a
     * path.
     */
    private function safeFileName(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');

        $stem = str(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->slug()
            ->limit(60, '')
            ->toString();

        return ($stem === '' ? 'document' : $stem).'.'.$extension;
    }
}
