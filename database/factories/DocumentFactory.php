<?php

namespace Database\Factories;

use App\Domain\Leads\Models\Lead;
use App\Domain\Timeline\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'documentable_type' => (new Lead)->getMorphClass(),
            'documentable_id' => Lead::factory(),
            'uploaded_by_id' => User::factory(),
            'title' => fake()->words(3, true).'.pdf',
            'description' => fake()->boolean() ? fake()->sentence() : null,
        ];
    }

    public function on(Model $subject): self
    {
        return $this->state(fn () => [
            'documentable_type' => $subject->getMorphClass(),
            'documentable_id' => $subject->getKey(),
        ]);
    }

    public function by(User $uploader): self
    {
        return $this->state(fn () => ['uploaded_by_id' => $uploader->id]);
    }

    /**
     * A document with an actual file behind it. Off by default: most tests care
     * about ordering and authorization rather than bytes, and writing a file
     * for every row would make them slow for nothing.
     */
    public function withFile(string $fileName = 'contract.pdf', int $kilobytes = 8): self
    {
        return $this->afterCreating(function (Document $document) use ($fileName, $kilobytes) {
            $document->addMedia(UploadedFile::fake()->create($fileName, $kilobytes, 'application/pdf'))
                ->usingFileName($fileName)
                ->toMediaCollection('file');
        });
    }
}
