<?php

namespace App\Domain\Meta\Auth;

use Illuminate\Support\Carbon;

/**
 * An access token and when it stops working.
 *
 * The expiry travels with the token because the two are only useful together: a
 * token whose expiry was dropped somewhere between the exchange and the database
 * is one that fails on an ordinary Tuesday with an error about permissions,
 * which is the wrong place to start looking.
 *
 * A null expiry means it does not expire — what a system user token does — not
 * that nobody asked.
 */
readonly class MetaToken
{
    public function __construct(
        public string $value,
        public ?Carbon $expiresAt = null,
    ) {}

    public function isExpired(?Carbon $at = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->lte($at ?? Carbon::now());
    }

    /**
     * Whether it is close enough to expiry to be worth renewing.
     *
     * Meta's long-lived tokens last about sixty days, and the failure mode
     * everybody hits is finding out on the day. A week's warning is enough to
     * put a banner on the settings screen and still have somebody act on it.
     */
    public function expiresSoon(int $days = 7): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->lte(Carbon::now()->addDays($days));
    }

    /**
     * Never the token itself.
     */
    public function describe(): string
    {
        return $this->expiresAt === null
            ? 'Does not expire'
            : 'Expires '.$this->expiresAt->toDayDateTimeString();
    }
}
