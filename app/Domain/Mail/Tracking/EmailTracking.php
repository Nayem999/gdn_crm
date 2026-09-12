<?php

namespace App\Domain\Mail\Tracking;

use Illuminate\Support\Facades\URL;

/**
 * The pixel and the rewritten links.
 *
 * **Every tracking URL is signed.** The click route takes the destination as a
 * parameter, and an unsigned one would be an open redirect with the company's
 * own domain in front of it — the exact thing a phishing campaign wants. The
 * signature is over the whole URL, so the destination cannot be swapped.
 *
 * The tracking id is ours, generated when the body is rendered. It has to be:
 * the pixel is in the body before anything has been handed to a provider, so it
 * cannot be an id the provider assigns.
 *
 * What this does not claim: an open is a *reported* open. Every mail client
 * that blocks remote images makes one invisible, and some privacy proxies fetch
 * every image whether or not a person looked. The number is a floor with noise
 * on top, not a fact, and it should be described that way wherever it is shown.
 */
final class EmailTracking
{
    /**
     * A 1x1 transparent GIF, the smallest thing that is still an image.
     */
    public const PIXEL = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    public static function openUrl(string $trackingId): string
    {
        return URL::signedRoute('mail.track.open', ['tracking' => $trackingId]);
    }

    public static function clickUrl(string $trackingId, string $destination): string
    {
        return URL::signedRoute('mail.track.click', ['tracking' => $trackingId, 'url' => $destination]);
    }

    /**
     * Put the pixel at the end of the body.
     *
     * Just before </body> when there is one, so it is inside the document; at
     * the end otherwise. Never at the top: a client that loads images lazily
     * would report an open for a message somebody scrolled past.
     */
    public static function withPixel(string $html, string $trackingId): string
    {
        $pixel = '<img src="'.e(self::openUrl($trackingId)).'" alt="" width="1" height="1" style="display:none">';

        $position = strripos($html, '</body>');

        return $position === false
            ? $html.$pixel
            : substr($html, 0, $position).$pixel.substr($html, $position);
    }

    /**
     * Point every http(s) link through the click route.
     *
     * Left alone: anything that is not http or https (mailto:, tel:), anything
     * already pointing at us, and the pixel itself. Rewriting a mailto would
     * turn "reply to us" into a dead link, which is a worse outcome than not
     * knowing it was clicked.
     */
    public static function withTrackedLinks(string $html, string $trackingId): string
    {
        return (string) preg_replace_callback(
            '/href=(["\'])(https?:\/\/[^"\']+)\1/i',
            function (array $matches) use ($trackingId): string {
                $destination = html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8');

                if (str_starts_with($destination, rtrim((string) config('app.url'), '/').'/e/')) {
                    return $matches[0];
                }

                return 'href='.$matches[1].e(self::clickUrl($trackingId, $destination)).$matches[1];
            },
            $html
        );
    }
}
