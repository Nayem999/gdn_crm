<?php

namespace App\Domain\Social\Actions;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use App\Domain\Social\Enums\TemplateStatus;
use App\Domain\Social\Models\WhatsAppTemplate;

/**
 * Reading what Meta has approved this business to say.
 *
 * Templates are authored and submitted in Meta's Business Manager and reviewed
 * there, so this only ever reads. What makes it worth running often is that the
 * **status changes without anybody here being told**: Meta pauses a template
 * when customers report or block the messages, and the first sign otherwise is a
 * send failing with a numeric code at the worst possible moment.
 *
 * Upserted and never deleted, like every other read of somebody else's
 * configuration in this phase. A template that has gone from Meta's answer keeps
 * its row, because messages already sent name it and a thread that could not say
 * which template it used would be a thread nobody can audit.
 */
class SyncWhatsAppTemplatesAction
{
    /**
     * What Meta is asked for. `components` is where the body, header, footer and
     * the placeholder examples all live.
     */
    private const FIELDS = 'name,language,category,status,components,rejected_reason';

    public function __construct(private readonly MetaGraphClient $client) {}

    /**
     * @return array{templates: int, approved: int}
     *
     * @throws MetaApiException
     */
    public function __invoke(WhatsAppBusinessAccount $account): array
    {
        $token = $account->access_token;

        if (! is_string($token) || $token === '') {
            throw new MetaApiException(sprintf(
                '"%s" has no access token. Reconnect it under Settings → Meta.',
                $account->name,
            ));
        }

        $seen = 0;
        $approved = 0;

        foreach ($this->client->paginate($account->waba_id.'/message_templates', [
            'fields' => self::FIELDS,
            'limit' => 200,
        ], $token) as $row) {
            $name = $this->text($row, 'name');
            $language = $this->text($row, 'language');

            if ($name === null || $language === null) {
                continue;
            }

            $status = TemplateStatus::fromMeta($this->text($row, 'status'));
            $parts = $this->components($row);

            WhatsAppTemplate::query()->updateOrCreate(
                // Meta identifies a template by this pair: one name exists in as
                // many languages as it has been translated into, each approved
                // separately.
                ['name' => $name, 'language' => $language],
                [
                    'waba_id' => $account->waba_id,
                    'category' => $this->text($row, 'category'),
                    'status' => $status->value,
                    'body' => $parts['body'],
                    'header' => $parts['header'],
                    'footer' => $parts['footer'],
                    'variables' => $parts['variables'],
                    'rejection_reason' => $this->text($row, 'rejected_reason'),
                    'synced_at' => now(),
                ]
            );

            $seen++;
            $approved += $status->isSendable() ? 1 : 0;
        }

        return ['templates' => $seen, 'approved' => $approved];
    }

    /**
     * The parts of a template, out of Meta's `components` array.
     *
     * @param  array<string, mixed>  $row
     * @return array{body: string|null, header: string|null, footer: string|null, variables: array<int, string>}
     */
    private function components(array $row): array
    {
        $parts = ['body' => null, 'header' => null, 'footer' => null, 'variables' => []];

        foreach (is_array($row['components'] ?? null) ? $row['components'] : [] as $component) {
            if (! is_array($component)) {
                continue;
            }

            $text = $this->text($component, 'text');

            match (strtoupper((string) $this->text($component, 'type'))) {
                'BODY' => $parts['body'] = $text,
                'HEADER' => $parts['header'] = $text,
                'FOOTER' => $parts['footer'] = $text,
                default => null,
            };
        }

        $parts['variables'] = $this->variables($parts['body']);

        return $parts;
    }

    /**
     * The placeholders the body actually contains, in order.
     *
     * Read from the text rather than from Meta's `example` block, because the
     * example is optional and the text is not: a template whose author never
     * filled the examples in still has `{{1}}` in its body, and sending it with
     * no parameters fails.
     *
     * @return array<int, string>
     */
    private function variables(?string $body): array
    {
        if ($body === null || $body === '') {
            return [];
        }

        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);

        $numbers = array_values(array_unique($matches[1]));

        // Numerically, so {{10}} comes after {{9}} rather than after {{1}} —
        // the order is what the values are matched to at send time.
        usort($numbers, fn (string $a, string $b): int => (int) $a <=> (int) $b);

        return $numbers;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function text(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
