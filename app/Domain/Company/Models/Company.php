<?php

namespace App\Domain\Company\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Shared\RequestMemo;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Company extends Model implements HasMedia
{
    /**
     * The container key the resolved company is memoised under.
     */
    private const MEMO_KEY = 'company.current';

    /** @use HasFactory<CompanyFactory> */
    use HasFactory, InteractsWithMedia, RecordsActivity;

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
            // Deliberately absent from $fillable: the installation marker is
            // written by the wizard, never by a form.
            'installed_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return $this->fillable;
    }

    /**
     * This is a single-organization install: fetch the one company profile,
     * creating it with sensible defaults on first access.
     */
    public static function current(): self
    {
        /** @var self $company */
        $company = app(RequestMemo::class)->remember(self::MEMO_KEY, fn (): self => static::query()->firstOrCreate([], [
            'name' => config('app.name'),
            'timezone' => config('app.timezone', 'UTC'),
            'currency' => 'USD',
            'fiscal_year_start_month' => 1,
        ]));

        // Memoised for the life of the request, because this is read far more
        // often than it looks: DisplayTime asks for the office timezone every
        // time a date is formatted, so a list of fifteen records ran fifteen
        // `select * from companies` — and so did every other screen with a date
        // on it.
        return $company;
    }

    /**
     * Drop the memo, so a request that edits the company profile and then reads
     * it back gets what it just saved.
     */
    public static function forgetCurrent(): void
    {
        app(RequestMemo::class)->forget(self::MEMO_KEY);
    }

    protected static function booted(): void
    {
        // Saving the profile makes the memo stale within the same request —
        // which is exactly what the settings screen does before re-rendering.
        static::saved(fn () => self::forgetCurrent());
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
