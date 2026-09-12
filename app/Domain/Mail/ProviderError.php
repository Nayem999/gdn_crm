<?php

namespace App\Domain\Mail;

use Illuminate\Http\Client\Response;

/**
 * The provider's own words about why it said no.
 *
 * Every one of these APIs reports a failure differently and none of them do it
 * the way the others do, so the shapes are tried in turn. It matters because
 * "Domain not found" and "Unauthorized" need completely different fixes, and
 * collapsing both into "sending failed" is how somebody spends an afternoon
 * re-typing a correct API key.
 */
final class ProviderError
{
    public static function from(Response $response): ?string
    {
        $body = $response->json();

        if (is_array($body)) {
            foreach (['message', 'Message', 'error', 'detail'] as $key) {
                if (isset($body[$key]) && is_string($body[$key])) {
                    return $body[$key];
                }
            }

            // SendGrid reports a list of problems rather than one.
            if (isset($body['errors']) && is_array($body['errors'])) {
                $messages = array_filter(array_map(
                    fn ($error) => is_array($error) && isset($error['message']) && is_string($error['message']) ? $error['message'] : null,
                    $body['errors']
                ));

                if ($messages !== []) {
                    return implode('; ', $messages);
                }
            }
        }

        $text = trim($response->body());

        return $text === '' ? null : mb_substr($text, 0, 300);
    }

    public static function describe(Response $response, string $provider): string
    {
        $message = self::from($response);

        return sprintf(
            '%s refused the request (HTTP %d)%s',
            $provider,
            $response->status(),
            $message === null ? '.' : ': '.$message,
        );
    }
}
