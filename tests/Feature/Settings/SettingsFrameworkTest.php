<?php

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\NumberFormat;
use App\Domain\Settings\SettingsManager;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\In;

beforeEach(function () {
    Cache::flush();
    app(SettingsManager::class)->flush();
});

test('a value saves and reads back through the helper', function () {
    settings()->set('localisation.date_format', 'Y-m-d');

    expect(settings('localisation.date_format'))->toBe('Y-m-d');
});

test('the helper with no argument hands back the manager', function () {
    expect(settings())->toBeInstanceOf(SettingsManager::class);
});

test('an unset setting falls back to the registry default', function () {
    expect(settings('localisation.date_format'))->toBe('j M Y')
        ->and(settings('storage.driver'))->toBe('local');
});

test('an explicit default wins over the registry default', function () {
    expect(settings('localisation.date_format', 'd/m/Y'))->toBe('d/m/Y');
});

test('each type round-trips as the type it was declared', function () {
    expect(SettingType::Integer->cast(SettingType::Integer->serialise('42')))->toBe(42)
        ->and(SettingType::Float->cast(SettingType::Float->serialise('1.5')))->toBe(1.5)
        ->and(SettingType::Boolean->cast(SettingType::Boolean->serialise(true)))->toBeTrue()
        ->and(SettingType::Boolean->cast(SettingType::Boolean->serialise(false)))->toBeFalse()
        ->and(SettingType::Json->cast(SettingType::Json->serialise(['a' => 1])))->toBe(['a' => 1])
        ->and(SettingType::String->cast(SettingType::String->serialise('hello')))->toBe('hello');
});

test('a setting the registry does not declare is refused, not created', function () {
    expect(settings()->set('localisation.made_up', 'x'))->toBeFalse()
        ->and(settings()->set('nonsense.key', 'x'))->toBeFalse()
        ->and(settings('localisation.made_up', 'fallback'))->toBe('fallback')
        ->and(Setting::query()->count())->toBe(0);
});

test('a name with no group is refused', function () {
    expect(SettingsRegistry::find('bare'))->toBeNull()
        ->and(settings()->set('bare', 'x'))->toBeFalse();
});

test('the row stores the type and secrecy the registry declares, not the caller', function () {
    settings()->set('storage.s3_key', 'AKIAEXAMPLE');

    $row = Setting::query()->where('group', 'storage')->where('key', 's3_key')->firstOrFail();

    expect($row->is_secret)->toBeTrue()
        ->and($row->type)->toBe('string');
});

// -- Encryption ---------------------------------------------------------------

test('a secret is encrypted at rest and never stored in the clear', function () {
    settings()->set('storage.s3_secret', 'super-secret-value');

    $stored = DB::table('settings')->where('group', 'storage')->where('key', 's3_secret')->value('value');

    expect($stored)->not->toBeNull()
        ->and($stored)->not->toContain('super-secret-value')
        ->and(Crypt::decryptString($stored))->toBe('super-secret-value')
        // And it reads back correctly through the accessor.
        ->and(settings('storage.s3_secret'))->toBe('super-secret-value');
});

test('an ordinary setting is stored in the clear, so it stays queryable', function () {
    settings()->set('localisation.week_starts_on', 'sunday');

    expect(DB::table('settings')->where('key', 'week_starts_on')->value('value'))->toBe('sunday');
});

test('a secret that cannot be decrypted reads as absent rather than throwing', function () {
    settings()->set('storage.s3_secret', 'valid');

    DB::table('settings')->where('key', 's3_secret')->update(['value' => 'not-actually-ciphertext']);
    settings()->flush();

    expect(settings('storage.s3_secret'))->toBeNull();
});

test('the model keeps its stored value out of anything that serialises it', function () {
    settings()->set('storage.s3_secret', 'super-secret-value');

    $row = Setting::query()->where('key', 's3_secret')->firstOrFail();

    expect($row->toArray())->not->toHaveKey('value')
        ->and(json_encode($row))->not->toContain('super-secret-value');
});

test('isSet reports a stored secret without revealing it', function () {
    expect(settings()->isSet('storage.s3_key'))->toBeFalse();

    settings()->set('storage.s3_key', 'AKIAEXAMPLE');

    expect(settings()->isSet('storage.s3_key'))->toBeTrue();
});

test('reading a whole group leaves secrets out', function () {
    settings()->set('storage.s3_key', 'AKIAEXAMPLE');
    settings()->set('storage.s3_bucket', 'uploads');

    $group = settings()->forGroup('storage');

    expect($group)->toHaveKey('s3_bucket')
        ->and($group['s3_bucket'])->toBe('uploads')
        ->and($group)->not->toHaveKey('s3_key')
        ->and($group)->not->toHaveKey('s3_secret')
        ->and(json_encode($group))->not->toContain('AKIAEXAMPLE');
});

// -- Cache --------------------------------------------------------------------

test('reads are cached per group', function () {
    settings()->set('localisation.date_format', 'Y-m-d');
    settings()->flush();

    expect(Cache::has(SettingsManager::CACHE_PREFIX.'localisation'))->toBeFalse();

    settings('localisation.date_format');

    expect(Cache::has(SettingsManager::CACHE_PREFIX.'localisation'))->toBeTrue();
});

test('the cache invalidates on save, so the next read sees the new value', function () {
    settings()->set('localisation.date_format', 'Y-m-d');
    expect(settings('localisation.date_format'))->toBe('Y-m-d');

    // A second manager stands in for the next request.
    settings()->set('localisation.date_format', 'd/m/Y');

    expect(app()->make(SettingsManager::class)->get('localisation.date_format'))->toBe('d/m/Y')
        ->and((new SettingsManager)->get('localisation.date_format'))->toBe('d/m/Y');
});

test('the cache holds ciphertext, never a decrypted secret', function () {
    settings()->set('storage.s3_secret', 'super-secret-value');
    settings('storage.s3_secret');

    $cached = Cache::get(SettingsManager::CACHE_PREFIX.'storage');

    // What is cached is the stored text, which for a secret is ciphertext.
    expect($cached)->toBeArray()
        ->and(json_encode($cached))->not->toContain('super-secret-value')
        ->and(Crypt::decryptString($cached['s3_secret']['value']))->toBe('super-secret-value');
});

test('forgetting a setting drops it and its cache entry', function () {
    settings()->set('localisation.date_format', 'Y-m-d');
    settings('localisation.date_format');

    settings()->forget('localisation.date_format');

    expect(Setting::query()->where('key', 'date_format')->exists())->toBeFalse()
        ->and((new SettingsManager)->get('localisation.date_format'))->toBe('j M Y');
});

test('flushing with no group clears every group', function () {
    settings()->set('localisation.date_format', 'Y-m-d');
    settings()->set('storage.s3_bucket', 'uploads');
    settings('localisation.date_format');
    settings('storage.s3_bucket');

    settings()->flush();

    foreach (SettingsRegistry::groupKeys() as $group) {
        expect(Cache::has(SettingsManager::CACHE_PREFIX.$group))->toBeFalse();
    }
});

// -- Registry -----------------------------------------------------------------

test('every declared field has a label and a resolvable type', function (string $name) {
    $field = SettingsRegistry::find($name);

    expect($field)->not->toBeNull()
        ->and($field->label)->not->toBeEmpty()
        ->and($field->rules())->not->toBeEmpty();
})->with(SettingsRegistry::all());

test('a select field only accepts one of its own options', function () {
    $field = SettingsRegistry::find('localisation.week_starts_on');

    expect($field->rules())->toContain('required')
        ->and(collect($field->rules())->contains(fn ($rule) => $rule instanceof In))->toBeTrue();

    Validator::make(['v' => 'monday'], ['v' => $field->rules()])->validate();

    expect(Validator::make(['v' => 'friday'], ['v' => $field->rules()])->fails())->toBeTrue();
});

test('separators are stored as tokens and resolve to real glyphs', function () {
    // A literal " " could never be stored: Laravel's "required" rule trims
    // strings, so a lone space reads as empty and always fails.
    expect(Validator::make(['v' => ' '], ['v' => ['required', 'string']])->fails())->toBeTrue();

    settings()->set('localisation.thousands_separator', 'space');
    settings()->set('localisation.decimal_separator', 'comma');

    expect(NumberFormat::thousands())->toBe(' ')
        ->and(NumberFormat::decimal())->toBe(',')
        ->and(NumberFormat::format(1234567.5))->toBe('1 234 567,50');

    settings()->set('localisation.thousands_separator', 'none');

    expect(NumberFormat::format(1234567.5))->toBe('1234567,50');
});

test('number formatting falls back to sensible defaults when nothing is set', function () {
    expect(NumberFormat::format(1234567.5))->toBe('1,234,567.50');
});

test('every select option in the registry validates against its own field', function (string $name) {
    $field = SettingsRegistry::find($name);

    if ($field->options === []) {
        expect(true)->toBeTrue();

        return;
    }

    foreach (array_keys($field->options) as $option) {
        expect(Validator::make(['v' => $option], ['v' => $field->rules()])->fails())
            ->toBeFalse("option [{$option}] of [{$name}] does not pass its own rules");
    }
})->with(SettingsRegistry::all());

test('every group declares a label, icon, description and at least one field', function (string $group) {
    $declared = SettingsRegistry::group($group);

    expect($declared['label'])->not->toBeEmpty()
        ->and($declared['icon'])->not->toBeEmpty()
        ->and($declared['description'])->not->toBeEmpty()
        ->and($declared['fields'])->not->toBeEmpty();
})->with(SettingsRegistry::groupKeys());

test('the registry knows which names are secret', function () {
    expect(SettingsRegistry::isSecret('storage.s3_secret'))->toBeTrue()
        ->and(SettingsRegistry::isSecret('storage.s3_key'))->toBeTrue()
        ->and(SettingsRegistry::isSecret('storage.s3_bucket'))->toBeFalse()
        ->and(SettingsRegistry::isSecret('localisation.date_format'))->toBeFalse()
        ->and(SettingsRegistry::isSecret('made.up'))->toBeFalse();
});
