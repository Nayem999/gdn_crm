<?php

namespace App\Domain\Timeline\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Models\User;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A file somebody attached to a record.
 *
 * @property int $id
 * @property string $documentable_type
 * @property int $documentable_id
 * @property int|null $uploaded_by_id
 * @property string $title
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $uploadedBy
 * @property-read Model|null $documentable
 */
class Document extends Model implements HasMedia
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use RecordsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['documentable_type', 'documentable_id', 'uploaded_by_id', 'title', 'description'];

    /**
     * The file itself is never logged — only that one was attached, by whom,
     * to what.
     *
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['documentable_type', 'documentable_id', 'uploaded_by_id', 'title', 'description'];
    }

    /**
     * The private disk, not the public one. A customer contract served from
     * /storage would be readable by anyone who guessed the path, so the bytes
     * stay outside the web root and are streamed through documents.download,
     * which asks the policy first.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')
            ->useDisk('local')
            ->singleFile();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * Named mediaFile, not file: a public no-arg method whose name is not a
     * column is what Laravel resolves `$document->file` through, and it would
     * fail asking for a relationship instance. See
     * .ai/rules/models-name-collisions.md for the shape of that trap.
     */
    public function mediaFile(): ?Media
    {
        return $this->getFirstMedia('file');
    }

    public function fileName(): ?string
    {
        return $this->mediaFile()?->file_name;
    }

    public function mimeType(): ?string
    {
        return $this->mediaFile()?->mime_type;
    }

    public function sizeInBytes(): int
    {
        $file = $this->mediaFile();

        return $file === null ? 0 : (int) $file->size;
    }

    /**
     * A size a person can read, rather than a number of bytes.
     */
    public function readableSize(): string
    {
        $bytes = $this->sizeInBytes();

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        foreach (['KB', 'MB', 'GB'] as $index => $unit) {
            $value = $bytes / (1024 ** ($index + 1));

            if ($value < 1024 || $unit === 'GB') {
                return round($value, $value < 10 ? 1 : 0).' '.$unit;
            }
        }

        return $bytes.' B';
    }

    /**
     * The icon for the kind of file this is, so a PDF and a spreadsheet do not
     * look identical in a list of attachments.
     */
    public function icon(): string
    {
        $mime = (string) $this->mimeType();

        return match (true) {
            str_starts_with($mime, 'image/') => 'lucide-image',
            $mime === 'application/pdf' => 'lucide-file-text',
            str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel') || $mime === 'text/csv' => 'lucide-file-spreadsheet',
            str_contains($mime, 'word') || str_contains($mime, 'document') => 'lucide-file-type',
            str_contains($mime, 'zip') || str_contains($mime, 'compressed') => 'lucide-file-archive',
            default => 'lucide-file',
        };
    }
}
