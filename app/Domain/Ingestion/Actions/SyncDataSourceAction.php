<?php

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Enums\DataSourceType;
use App\Domain\Ingestion\Enums\PullAuth;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Ingestion\PayloadReader;
use App\Domain\Workflows\Webhooks\WebhookTarget;
use App\Jobs\ProcessIntegrationEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Fetches from their system and turns each record into a delivery.
 *
 * The pull half of the gateway. Everything after "we have a record" is the
 * **same code a pushed delivery goes through** — one `integration_events` row
 * each, then the pipeline — so a pulled lead and a pushed one are mapped,
 * deduplicated, validated and assigned identically, and the event log answers
 * "where did this come from" the same way for both.
 *
 * The alternative, a second path that wrote records directly, would be a second
 * set of rules to keep in step with the first, and the one that drifts is
 * always the one nobody looks at.
 */
class SyncDataSourceAction
{
    /**
     * A ceiling on pages per run. Their pagination is their code: a response
     * that always says there is another page would otherwise fetch for ever,
     * and a sync that never finishes is a sync that blocks every later one.
     */
    public const MAX_PAGES = 200;

    /**
     * @return array<string, mixed> what the run did, for the screen and the log
     */
    public function __invoke(DataSource $source): array
    {
        $started = now();

        try {
            $summary = $this->run($source);
        } catch (Throwable $exception) {
            $summary = [
                'ok' => false,
                // The class and the message, never the response body: their
                // error page can contain anything, including our own token
                // echoed back.
                'error' => class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 300),
                'pages' => 0,
                'records' => 0,
            ];
        }

        $summary['at'] = $started->toDateTimeString();

        $source->forceFill([
            'last_synced_at' => $started,
            'last_sync_summary' => $summary,
        ])->save();

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function run(DataSource $source): array
    {
        if ($source->type() !== DataSourceType::Pull) {
            throw new RuntimeException('This is not a pull source.');
        }

        if (! $source->acceptsDeliveries()) {
            throw new RuntimeException('This source is switched off.');
        }

        $url = (string) $source->pull_url;

        // The same guard the outbound webhook uses. An administrator typing a
        // URL is still somebody typing a URL, and this one is fetched by the
        // server with the server's own network access.
        $refusal = WebhookTarget::refuse($url);

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        $records = 0;
        $pages = 0;
        $cursor = $source->pull_cursor;
        $highest = $cursor;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $rows = $this->fetchPage($source, $page, $cursor);
            $pages++;

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $this->deliver($source, $row);
                $records++;

                $highest = $this->advance($source, $row, $highest);
            }

            // Their last page is a short one. Asking for one more and getting
            // nothing is the reliable end condition, but a full page followed
            // by an empty one costs a request; a short page ends it here.
            if (count($rows) < max(1, (int) $source->pull_page_size)) {
                break;
            }
        }

        // Written once, at the end. Advancing per record would mean a run that
        // failed halfway left the mark past records it never delivered.
        if ($highest !== null && $highest !== $source->pull_cursor) {
            $source->forceFill(['pull_cursor' => $highest])->save();
        }

        return ['ok' => true, 'pages' => $pages, 'records' => $records, 'cursor' => $highest];
    }

    /**
     * @return array<int, mixed>
     */
    private function fetchPage(DataSource $source, int $page, ?string $cursor): array
    {
        $query = [];

        if (filled($source->pull_page_param)) {
            $query[(string) $source->pull_page_param] = $page;
        }

        if (filled($source->pull_page_size_param)) {
            $query[(string) $source->pull_page_size_param] = (int) $source->pull_page_size;
        }

        // Only when we have one. A first run asks for everything, which is what
        // "import the backlog" means.
        if (filled($source->pull_cursor_param) && $cursor !== null && $cursor !== '') {
            $query[(string) $source->pull_cursor_param] = $cursor;
        }

        $response = $this->request($source)->get((string) $source->pull_url, $query);

        if ($response->failed()) {
            throw new RuntimeException('They answered '.$response->status().'.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Their response was not JSON.');
        }

        $path = (string) $source->pull_records_path;

        if ($path === '') {
            // A bare list is a perfectly ordinary shape for an endpoint that
            // does one thing.
            return array_is_list($body) ? $body : [];
        }

        $rows = $this->descend($body, $path);

        return is_array($rows) && array_is_list($rows) ? $rows : [];
    }

    /**
     * Walk to the records inside their envelope.
     *
     * `PayloadReader::value` deliberately refuses a branch, because a mapping
     * must never produce one — here a branch is exactly what we want.
     *
     * @param  array<string, mixed>  $body
     */
    private function descend(array $body, string $path): mixed
    {
        $current = $body;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function request(DataSource $source): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout(30)
            // Their outage should not become ours: a fetch that hangs holds a
            // queue worker, and the next scheduled run piles up behind it.
            ->connectTimeout(10);

        $secret = $source->pull_auth_secret;
        $name = (string) $source->pull_auth_name;

        return match ($source->pullAuth()) {
            PullAuth::Bearer => $request->withToken((string) $secret),
            PullAuth::Basic => $request->withBasicAuth($name, (string) $secret),
            PullAuth::Header => $name === '' ? $request : $request->withHeader($name, (string) $secret),
            PullAuth::None => $request,
        };
    }

    /**
     * One record becomes one delivery, captured exactly as a pushed one is.
     *
     * @param  array<string, mixed>  $row
     */
    private function deliver(DataSource $source, array $row): void
    {
        $body = (string) json_encode($row);

        $event = new IntegrationEvent;
        $event->forceFill([
            'data_source_id' => $source->getKey(),
            'payload' => $body,
            'body_hash' => hash('sha256', $body),
            // Nothing signed it — we fetched it. Saying otherwise would make
            // the log claim a guarantee that was never made.
            'signature_verified' => false,
            'is_sandbox' => (bool) $source->is_sandbox,
            'received_at' => now(),
        ])->save();

        ProcessIntegrationEvent::dispatch($event->id);
    }

    /**
     * The furthest-along cursor value seen this run.
     *
     * Compared as strings, because it is **their** value — an id, a sequence
     * number or a date — and we do not get to decide which. String comparison
     * is right for an ISO date and for a zero-padded id, and for anything else
     * the cursor is only ever handed back to them.
     *
     * @param  array<string, mixed>  $row
     */
    private function advance(DataSource $source, array $row, ?string $highest): ?string
    {
        if (! filled($source->pull_cursor_path)) {
            return $highest;
        }

        $value = PayloadReader::value($row, (string) $source->pull_cursor_path);

        if ($value === null || $value === '') {
            return $highest;
        }

        $value = (string) $value;

        return $highest === null || strcmp($value, $highest) > 0 ? $value : $highest;
    }
}
