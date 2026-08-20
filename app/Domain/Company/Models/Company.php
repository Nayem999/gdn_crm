<?php

namespace App\Domain\Company\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Company extends Model implements HasMedia
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'timezone',
        'currency',
        'fiscal_year_start_month',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year_start_month' => 'integer',
        ];
    }

    /**
     * This is a single-organization install: fetch the one company profile,
     * creating it with sensible defaults on first access.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'name' => config('app.name'),
            'timezone' => config('app.timezone', 'UTC'),
            'currency' => 'USD',
            'fiscal_year_start_month' => 1,
        ]);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
    }

    public function logoUrl(): ?string
    {
        return $this->getFirstMedia('logo')?->getUrl();
    }
}
