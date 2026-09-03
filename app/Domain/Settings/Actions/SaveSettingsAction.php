<?php

namespace App\Domain\Settings\Actions;

use App\Domain\Notifications\Notifier;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsManager;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saves one group of settings and records what changed.
 *
 * Two rules hold here and nowhere else is allowed to bypass them: only keys the
 * registry declares are written, and the audit entry never carries a secret's
 * value — only the fact that the key changed.
 */
class SaveSettingsAction
{
    public function __construct(private readonly SettingsManager $settings) {}

    /**
     * @param  array<string, mixed>  $values  Keys within the group, e.g. "s3_key".
     * @param  bool  $includeSecrets  False when the actor may not manage secrets.
     * @return array<int, string> The keys that actually changed.
     */
    public function handle(string $group, array $values, bool $includeSecrets = true): array
    {
        $fields = SettingsRegistry::fields($group);

        if ($fields === []) {
            return [];
        }

        $changed = [];

        DB::transaction(function () use ($fields, $group, $values, $includeSecrets, &$changed) {
            foreach ($fields as $key => $field) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                if ($field->secret && ! $includeSecrets) {
                    continue;
                }

                $value = $values[$key];

                // A secret left blank means "keep what is stored" — the form
                // never round-trips the existing value, so a blank submission
                // must not wipe it. Clearing one is an explicit action.
                if ($field->secret && ($value === null || $value === '')) {
                    continue;
                }

                if (! $field->secret && $this->settings->get($group.'.'.$key) === $field->type->cast($field->type->serialise($value))) {
                    continue;
                }

                if ($this->settings->set($group.'.'.$key, $value)) {
                    $changed[] = $key;
                }
            }
        });

        if ($changed !== []) {
            $this->audit($group, $changed, $values);
            $this->announceCredentialChange($group, $changed);
        }

        return $changed;
    }

    /**
     * Remove a secret entirely, so the group falls back to having none.
     */
    public function clearSecret(string $group, string $key): bool
    {
        $field = SettingsRegistry::fields($group)[$key] ?? null;

        if ($field === null || ! $field->secret || ! $this->settings->isSet($group.'.'.$key)) {
            return false;
        }

        $this->settings->forget($group.'.'.$key);
        $this->audit($group, [$key], [], 'cleared');
        $this->announceCredentialChange($group, [$key]);

        return true;
    }

    /**
     * Tell the other administrators when a stored credential moved.
     *
     * Only the key names travel — the same rule the audit trail follows. A
     * change to an ordinary setting is not worth a notification.
     *
     * @param  array<int, string>  $changed
     */
    private function announceCredentialChange(string $group, array $changed): void
    {
        $fields = SettingsRegistry::fields($group);

        $secrets = array_values(array_filter(
            $changed,
            fn (string $key) => ($fields[$key] ?? null)?->secret === true
        ));

        if ($secrets === []) {
            return;
        }

        $actor = auth()->user();

        app(Notifier::class)->sendToAdmins('settings.credential_changed', 'settings.secrets', [
            'settings' => [
                'group' => SettingsRegistry::group($group)['label'],
                'keys' => $secrets,
            ],
            'actor' => ['name' => $actor === null ? 'The system' : $actor->name],
        ], $actor instanceof User ? $actor : null);
    }

    /**
     * Record who changed which keys, and when.
     *
     * A secret's value never reaches the trail — not the old one, not the new
     * one, not its length. Ordinary settings record their new value, which is
     * what makes the trail useful for the rest of the groups.
     *
     * @param  array<int, string>  $changed
     * @param  array<string, mixed>  $values
     */
    private function audit(string $group, array $changed, array $values, string $verb = 'updated'): void
    {
        $fields = SettingsRegistry::fields($group);

        $recorded = [];

        foreach ($changed as $key) {
            $recorded[$key] = ($fields[$key] ?? null)?->secret
                ? '[secret changed]'
                : ($values[$key] ?? null);
        }

        // The group as a whole is what changed, so any surviving row in it
        // stands as the subject. Clearing the last secret in a group can leave
        // none, and the viewer already copes with a subject-less entry.
        $subject = Setting::query()->where('group', $group)->first();

        $entry = activity('audit');

        if ($subject !== null) {
            $entry->performedOn($subject);
        }

        $entry
            ->event($verb === 'cleared' ? 'deleted' : 'updated')
            ->withProperties([
                'attributes' => [
                    'group' => $group,
                    'keys' => $changed,
                    'values' => $recorded,
                ],
            ])
            ->log(SettingsRegistry::group($group)['label'].' settings were '.$verb);
    }
}
