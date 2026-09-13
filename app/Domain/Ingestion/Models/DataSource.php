<?php

namespace App\Domain\Ingestion\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Ingestion\Enums\DataSourceType;
use App\Domain\Ingestion\Enums\DedupeAction;
use App\Domain\Ingestion\IngestionTargets;
use App\Domain\Ingestion\PayloadReader;
use App\Domain\Ingestion\SourceSecret;
use App\Models\User;
use Database\Factories\DataSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Somewhere records are allowed to come in from.
 *
 * Configuration, not a business record: there is no owner and no access level,
 * because an integration belongs to the installation rather than to a
 * salesperson. Who may see and change one is a permission, and only that.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property string $target_module
 * @property bool $is_active
 * @property bool $is_sandbox
 * @property array<int, string>|null $ip_allowlist
 * @property bool $requires_key
 * @property bool $requires_signature
 * @property string|null $external_id_path
 * @property array<int, string>|null $dedupe_fields
 * @property string $dedupe_action
 * @property int|null $default_owner_id
 * @property string|null $sample_payload
 * @property Carbon|null $sample_captured_at
 * @property Carbon|null $listening_until
 * @property int|null $created_by_id
 * @property string|null $secret_hash
 * @property string|null $secret_hint
 * @property string|null $signing_secret
 * @property Carbon|null $secret_created_at
 * @property string|null $previous_secret_hash
 * @property string|null $previous_signing_secret
 * @property Carbon|null $previous_secret_expires_at
 * @property Carbon|null $secret_revoked_at
 * @property Carbon|null $deleted_at
 */
class DataSource extends Model
{
    /** @use HasFactory<DataSourceFactory> */
    use HasFactory;

    use RecordsActivity;
    use SoftDeletes;

    /**
     * `uuid` is absent on purpose: it is the public identifier an outside
     * system has already been given, so nothing may submit or change it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'type',
        'target_module',
        'is_active',
        'is_sandbox',
        'ip_allowlist',
        'requires_key',
        'requires_signature',
        'external_id_path',
        'dedupe_fields',
        'dedupe_action',
        'default_owner_id',
        'created_by_id',
    ];

    /**
     * How long listen mode stays on before it gives up.
     *
     * A mode somebody switches on and forgets is a mode that quietly overwrites
     * the sample months later, halfway through a conversation about why the
     * mapping stopped matching.
     */
    public const LISTEN_MINUTES = 30;

    /**
     * Columns that share a name with a method on this model.
     *
     * Laravel decides whether a property read is a relation by looking for a
     * method of that name, so on an instance where the attribute is missing the
     * read calls the method and fails. Declaring defaults keeps the key present
     * however the record was made — see .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'push',
        'is_active' => true,
        'is_sandbox' => false,
        'requires_key' => true,
        'requires_signature' => true,
        'dedupe_action' => 'update',
    ];

    /**
     * Credentials never reach anything that serialises this model — an API
     * response, a queued job payload, a log line or a `dd()`.
     *
     * @var list<string>
     */
    protected $hidden = [
        'secret_hash',
        'signing_secret',
        'previous_secret_hash',
        'previous_signing_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_sandbox' => 'boolean',
            'requires_key' => 'boolean',
            'requires_signature' => 'boolean',
            'ip_allowlist' => 'array',
            'dedupe_fields' => 'array',
            'sample_captured_at' => 'datetime',
            'listening_until' => 'datetime',
            'secret_created_at' => 'datetime',
            'previous_secret_expires_at' => 'datetime',
            'secret_revoked_at' => 'datetime',
            // Encrypted, not hashed: verifying an HMAC means recomputing it,
            // which needs the value. See App\Domain\Ingestion\SourceSecret.
            'signing_secret' => 'encrypted',
            'previous_signing_secret' => 'encrypted',
        ];
    }

    /**
     * The uuid is minted here rather than by whoever happens to create a row.
     *
     * Every path that makes a source — the screen, a factory, a seeder, a later
     * phase's importer — needs one, and a source without a uuid has no ingest
     * address at all. A create() that forgot it would fail at the unique index
     * much later, or not at all on a database that allows one null.
     */
    protected static function booted(): void
    {
        static::creating(function (self $source) {
            if (! $source->getAttributeValue('uuid')) {
                $source->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * An explicit allowlist, never logAll().
     *
     * The secret columns are not here and must never be. The audit trail
     * records *that* a credential changed — which is written explicitly by the
     * screen — never anything about its value, its length or its old value.
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return [
            'name', 'description', 'type', 'target_module', 'is_active', 'is_sandbox',
            'ip_allowlist', 'requires_key', 'requires_signature',
            'external_id_path', 'dedupe_fields', 'dedupe_action', 'default_owner_id',
        ];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Data source';
    }

    // -- Relations ----------------------------------------------------------

    /**
     * @return HasMany<IntegrationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<DataSourceFilter, $this>
     */
    public function filters(): HasMany
    {
        return $this->hasMany(DataSourceFilter::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<DataSourceMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(DataSourceMapping::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function defaultOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    // -- Presentation --------------------------------------------------------

    public function displayName(): string
    {
        return $this->name;
    }

    /**
     * getAttributeValue, not $this->type: the method and the column share a
     * name, so a property read on an instance without that attribute loaded
     * would be taken for a relation and call this method again.
     */
    public function type(): DataSourceType
    {
        return DataSourceType::tryFrom((string) $this->getAttributeValue('type')) ?? DataSourceType::Push;
    }

    /**
     * Whether this source is waiting to capture a sample.
     *
     * By expiry, so nothing has to remember to switch it off.
     */
    public function isListening(): bool
    {
        return $this->listening_until !== null && $this->listening_until->isFuture();
    }

    public function hasSample(): bool
    {
        return $this->sample_payload !== null && trim($this->sample_payload) !== '';
    }

    /**
     * The sample, decoded, or null when there is not a usable one.
     *
     * @return array<string, mixed>|null
     */
    public function samplePayload(): ?array
    {
        return $this->sample_payload === null ? null : PayloadReader::decode($this->sample_payload);
    }

    /**
     * Every path in the sample, for the mapping screen to offer as something
     * clickable rather than something to type.
     *
     * @return array<int, string>
     */
    public function samplePaths(): array
    {
        $payload = $this->samplePayload();

        return $payload === null ? [] : PayloadReader::paths($payload);
    }

    public function dedupeAction(): DedupeAction
    {
        return DedupeAction::tryFrom((string) $this->getAttributeValue('dedupe_action')) ?? DedupeAction::Update;
    }

    public function targetLabel(): string
    {
        return IngestionTargets::label($this->target_module);
    }

    /**
     * The model class this source writes into, or null when its target module
     * is no longer one the application offers.
     *
     * @return class-string<Model>|null
     */
    public function targetModelClass(): ?string
    {
        return IngestionTargets::modelClass($this->target_module);
    }

    /**
     * Whether a delivery to this source should be accepted at all.
     *
     * The first gate, and deliberately the cheapest one: it is the answer
     * before a signature is checked or a body is read, so a source somebody
     * switched off costs nothing to refuse. Sandbox mode is **not** part of it
     * — a sandbox source still accepts and captures, it just does not write.
     */
    public function acceptsDeliveries(): bool
    {
        return $this->is_active && $this->deleted_at === null;
    }

    /**
     * Whether a delivery to this source may create or change a record.
     */
    public function writesRecords(): bool
    {
        return $this->acceptsDeliveries() && ! $this->is_sandbox;
    }

    /**
     * The ingest address an outside system posts to.
     *
     * Built from the uuid rather than stored, so it cannot drift from the route
     * and does not have to be rewritten when the application moves host.
     */
    public function ingestUrl(): string
    {
        return url('/api/ingest/'.$this->uuid);
    }

    // -- Credentials ---------------------------------------------------------

    /**
     * The grace window used when nothing has been configured.
     */
    public const DEFAULT_GRACE_HOURS = 24;

    /**
     * How long a rotated key keeps working, in hours.
     *
     * Configurable, because "long enough for the other side to deploy" is a
     * different number at every company. Read through the settings framework so
     * it is one administered value rather than a constant somebody has to find.
     */
    public static function rotationGraceHours(): int
    {
        return (int) settings('integrations.rotation_grace_hours', self::DEFAULT_GRACE_HOURS);
    }

    public function hasSecret(): bool
    {
        return $this->secret_hash !== null;
    }

    public function secretWasRevoked(): bool
    {
        return ! $this->hasSecret() && $this->secret_revoked_at !== null;
    }

    /**
     * Whether a rotated key is still inside its grace window.
     */
    public function isInGrace(): bool
    {
        return $this->previous_secret_hash !== null
            && $this->previous_secret_expires_at !== null
            && $this->previous_secret_expires_at->isFuture();
    }

    public function graceEndsAt(): ?Carbon
    {
        return $this->isInGrace() ? $this->previous_secret_expires_at : null;
    }

    /**
     * Whether a presented key authenticates this source.
     *
     * Constant-time against the current key, and against the previous one while
     * its grace window is open. The window is checked by **expiry stamp**, so
     * an old key stops working on the clock rather than when somebody
     * remembers to clear it — there is no sweep to forget to run.
     */
    public function verifyKey(?string $presented): bool
    {
        if ($presented === null || $presented === '') {
            return false;
        }

        if (SourceSecret::matches($presented, $this->secret_hash)) {
            return true;
        }

        return $this->isInGrace() && SourceSecret::matches($presented, $this->previous_secret_hash);
    }

    /**
     * The signing secrets a signature may have been made with: the current one,
     * and the previous one while its grace window is open.
     *
     * A list rather than one value, because during a rotation both are
     * legitimate and the sender decides which it used.
     *
     * @return array<int, string>
     */
    public function signingSecrets(): array
    {
        $secrets = [];

        if (is_string($this->signing_secret) && $this->signing_secret !== '') {
            $secrets[] = $this->signing_secret;
        }

        if ($this->isInGrace() && is_string($this->previous_signing_secret) && $this->previous_signing_secret !== '') {
            $secrets[] = $this->previous_signing_secret;
        }

        return $secrets;
    }

    // -- Queries -------------------------------------------------------------

    /**
     * The source one ingest path names, or null when there is nothing there to
     * take a delivery.
     *
     * One method, because "no such source", "that source was deleted" and "that
     * source is switched off" must be indistinguishable from outside: telling a
     * caller which of the three it is tells them which uuids exist.
     */
    public static function forIngest(string $uuid): ?self
    {
        $source = static::query()->where('uuid', $uuid)->first();

        return $source?->acceptsDeliveries() === true ? $source : null;
    }

    /**
     * @param  Builder<DataSource>  $query
     * @return Builder<DataSource>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<DataSource>  $query
     * @return Builder<DataSource>
     */
    public function scopeOfType(Builder $query, DataSourceType $type): Builder
    {
        return $query->where($query->qualifyColumn('type'), $type->value);
    }
}
