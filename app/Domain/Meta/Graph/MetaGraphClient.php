<?php

namespace App\Domain\Meta\Graph;

use App\Domain\Meta\MetaApiVersion;
use App\Domain\Meta\MetaConfiguration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Every call this application makes to Meta.
 *
 * One client rather than a client per service, because the things that must be
 * true of a Graph call are true of all of them and are easy to forget one at a
 * time: the version is the configured one, the token never appears in a log or
 * an exception, a throttled call backs off instead of hammering, a dead token
 * is not retried, and a response that is not JSON is an error rather than an
 * array of nulls.
 *
 * **Tokens are arguments, never state.** A page token, a user token and the
 * app token are different secrets with different lifetimes, and a client that
 * remembered one would use it for the other.
 *
 * `appsecret_proof` is sent with every authenticated call. Meta treats a token
 * used without it as more suspicious, and it is what stops a token stolen from
 * one of our logs — which should never happen, and is why it is worth planning
 * for — being usable anywhere else.
 */
class MetaGraphClient
{
    /**
     * The rate-limit usage Meta reported on the most recent successful call.
     *
     * @var array<string, array<mixed>>
     */
    private array $usage = [];

    public function __construct(private readonly MetaConfiguration $config) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws MetaApiException
     */
    public function get(string $path, array $query = [], ?string $token = null): array
    {
        return $this->send('get', $path, $query, $token);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws MetaApiException
     */
    public function post(string $path, array $payload = [], ?string $token = null): array
    {
        return $this->send('post', $path, $payload, $token);
    }

    /**
     * Follow Meta's cursors, yielding each page's `data` rows.
     *
     * A generator rather than one big array: a year of ad insights is tens of
     * thousands of rows, and the caller wants to write them away a page at a
     * time rather than hold the lot in memory.
     *
     * @param  array<string, mixed>  $query
     * @return iterable<int, array<string, mixed>>
     *
     * @throws MetaApiException
     */
    public function paginate(string $path, array $query = [], ?string $token = null, ?int $maxPages = null): iterable
    {
        $limit = $maxPages ?? (int) config('meta.max_pages', 50);
        $after = null;
        $pages = 0;

        do {
            $page = $this->get($path, $after === null ? $query : [...$query, 'after' => $after], $token);

            // Untyped on purpose: `data` is whatever Meta sent, and a row that
            // is not an object is a payload problem rather than something to
            // hand on to a caller expecting an array.
            /** @var array<int, mixed> $rows */
            $rows = is_array($page['data'] ?? null) ? $page['data'] : [];

            foreach ($rows as $row) {
                if (is_array($row)) {
                    yield $row;
                }
            }

            $after = $this->nextCursor($page);
            $pages++;

            // A cursor that never ends is a bug at one end or the other; the
            // ceiling is what stops it being an infinite job.
        } while ($after !== null && $pages < $limit);
    }

    /**
     * The app access token — `{app-id}|{app-secret}`.
     *
     * Meta's own documented form, and the only token that can be built without
     * anybody authorising anything. It can read app-level things (debugging a
     * token, the webhook subscription) and nothing belonging to a person.
     *
     * @throws MetaApiException when the app credentials are not configured.
     */
    public function appToken(): string
    {
        $id = $this->config->appId();
        $secret = $this->config->appSecret();

        if ($id === null || $secret === null) {
            throw new MetaApiException('The Meta app is not configured. Add the app ID and secret under Settings → Meta.');
        }

        return $id.'|'.$secret;
    }

    /**
     * What Meta says about a token: which app it belongs to, whether it is
     * valid, when it expires and what it may do.
     *
     * @return array<string, mixed>
     *
     * @throws MetaApiException
     */
    public function debugToken(string $token): array
    {
        $response = $this->get('debug_token', ['input_token' => $token], $this->appToken());

        /** @var array<string, mixed> $data */
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     *
     * @throws MetaApiException
     */
    private function send(string $method, string $path, array $parameters, ?string $token): array
    {
        $request = $this->request($token);
        $url = MetaApiVersion::baseUrl($this->config->version()).'/'.ltrim($path, '/');

        if ($token !== null) {
            // In the body for a write and the query for a read. Never in a
            // header we might later dump, and never in the path, which is what
            // a redirect would carry to somebody else's server.
            $parameters['appsecret_proof'] = $this->proof($token);
        }

        try {
            $response = $method === 'post'
                ? $request->asForm()->post($url, $parameters)
                : $request->get($url, $parameters);
        } catch (ConnectionException $exception) {
            throw MetaApiException::transport($exception->getMessage());
        } catch (Throwable $exception) {
            // Anything else that escaped the retry policy. The message is ours,
            // because the original could carry the URL — and the URL carries
            // the token on a GET.
            throw MetaApiException::transport('the request could not be completed');
        }

        return $this->decode($response);
    }

    private function request(?string $token): PendingRequest
    {
        $request = Http::withOptions([
            'connect_timeout' => (float) config('meta.connect_timeout', 10),
        ])
            ->timeout((int) config('meta.timeout', 20))
            ->acceptJson()
            // Meta's own errors come back as 4xx/5xx with a JSON body worth
            // reading, so the response is inspected rather than thrown on.
            ->retry(
                max(1, (int) config('meta.retries', 3)),
                max(0, (int) config('meta.retry_base_delay', 500)),
                fn (Throwable $exception, PendingRequest $request): bool => $this->shouldRetry($exception),
                throw: false,
            );

        if ($token !== null) {
            $request = $request->withToken($token);
        }

        return $request;
    }

    /**
     * Retry a connection failure, and nothing else.
     *
     * A 4xx from Meta is a decision — a bad token, a missing permission, a
     * malformed field list — and repeating it changes nothing while spending
     * the rate limit that the calls which could succeed need. Throttling and
     * 5xx are handled by the caller, which knows whether the work can wait.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException;
    }

    /**
     * HMAC-SHA256 of the token, keyed with the app secret.
     */
    private function proof(string $token): string
    {
        $secret = $this->config->appSecret();

        return $secret === null ? '' : hash_hmac('sha256', $token, $secret);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MetaApiException
     */
    private function decode(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new MetaApiException(
                'Meta returned a response that was not JSON.',
                status: $response->status(),
            );
        }

        /** @var array<string, mixed> $body */
        if ($response->failed() || isset($body['error'])) {
            throw MetaApiException::fromResponse($body, $response->status());
        }

        $this->recordUsage($response);

        return $body;
    }

    /**
     * Meta reports how much of each rate limit this call consumed, in headers
     * nobody reads until they are throttled.
     *
     * Nothing is thrown here: the call succeeded, and failing it after the fact
     * would lose work that is already done. It is recorded so a sync can ask
     * before starting the next page.
     */
    private function recordUsage(Response $response): void
    {
        foreach (['X-App-Usage', 'X-Business-Use-Case-Usage', 'X-Ad-Account-Usage'] as $header) {
            $value = $response->header($header);

            if ($value === '') {
                continue;
            }

            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                $this->usage[$header] = $decoded;
            }
        }
    }

    /**
     * The highest percentage of any rate limit Meta has reported so far, or
     * null when it has not said.
     *
     * A sync asks this between pages: carrying on past the ceiling gets the
     * whole app throttled, which breaks every other integration too, and the
     * remaining pages will still be there in an hour.
     */
    public function usagePercentage(): ?int
    {
        $highest = null;

        array_walk_recursive($this->usage, function (mixed $value, string|int $key) use (&$highest): void {
            if (! is_numeric($value)) {
                return;
            }

            if (in_array($key, ['call_count', 'total_cputime', 'total_time', 'estimated_time_to_regain_access'], true) === false) {
                return;
            }

            $highest = max($highest ?? 0, (int) $value);
        });

        return $highest;
    }

    public function isNearRateLimit(): bool
    {
        $usage = $this->usagePercentage();

        return $usage !== null && $usage >= (int) config('meta.usage_ceiling', 90);
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function nextCursor(array $page): ?string
    {
        $paging = is_array($page['paging'] ?? null) ? $page['paging'] : [];

        // `next` present is what means there is another page: cursors.after is
        // returned on the last page too, and following it forever is how a
        // paginated read becomes an infinite loop.
        if (! isset($paging['next'])) {
            return null;
        }

        $cursors = is_array($paging['cursors'] ?? null) ? $paging['cursors'] : [];
        $after = $cursors['after'] ?? null;

        return is_string($after) && $after !== '' ? $after : null;
    }
}
