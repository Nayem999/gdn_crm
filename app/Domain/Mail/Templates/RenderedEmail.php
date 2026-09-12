<?php

namespace App\Domain\Mail\Templates;

/**
 * A template, filled in, ready to send.
 *
 * The tracking id travels with it because the body already contains it: the
 * pixel and the rewritten links were written with it, and the message has to
 * carry the same one out in its header for the delivery log to join them up.
 */
final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $html,
        public ?string $trackingId = null,
    ) {}
}
