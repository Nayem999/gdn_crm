<?php

namespace App\Domain\Social;

use App\Domain\Social\Enums\SocialChannel;
use Illuminate\Support\Carbon;

/**
 * Meta's rule about when we are allowed to answer.
 *
 * The rule is **theirs**, and this application's job is to state it honestly
 * rather than to work around it. Messenger allows a free-form reply for seven
 * days after the customer's last message; WhatsApp allows twenty-four hours.
 * Outside that, only an approved template (WhatsApp) or a message tag
 * (Messenger) is permitted, and Meta refuses anything else — with a penalty to
 * the number's quality rating for trying.
 *
 * So there is one place that knows it, and three things read it: the inbox,
 * which disables the reply box and says why; the send action, which refuses
 * independently because a disabled control is a courtesy and not a boundary;
 * and 12.10's WhatsApp sending, which is the same rule with a different number
 * of hours.
 *
 * **The clock starts at the customer's last inbound message**, never at ours.
 * Replying does not extend the window — that is exactly the misunderstanding
 * that gets a number rate-limited, because it makes an agent believe a thread
 * is open when Meta has already closed it.
 */
final class MessagingWindow
{
    /**
     * When the window closes, given the moment the customer last wrote.
     */
    public static function expiresAt(SocialChannel $channel, Carbon $lastInboundAt): Carbon
    {
        return $lastInboundAt->copy()->addHours($channel->replyWindowHours());
    }

    /**
     * Whether a free-form reply is allowed now.
     *
     * A conversation with no recorded expiry has never had an inbound message —
     * an outbound-first thread — and Meta has not opened a window for it. False
     * is the honest answer, and the safe one.
     */
    public static function isOpen(?Carbon $expiresAt, ?Carbon $now = null): bool
    {
        return $expiresAt !== null && $expiresAt->isAfter($now ?? Carbon::now());
    }

    /**
     * Why a message cannot be sent, in the channel's own words, or null when it
     * can.
     *
     * Meta's reason rather than ours: somebody reading "the 24-hour window has
     * closed; only an approved template may be sent" can go and find that phrase
     * in Meta's documentation, where "sending failed" sends them nowhere.
     */
    public static function refusal(SocialChannel $channel, ?Carbon $expiresAt, ?Carbon $now = null): ?string
    {
        if (self::isOpen($expiresAt, $now)) {
            return null;
        }

        // The channel's own phrase, not one computed from its hours — see
        // SocialChannel::windowLabel().
        $window = $channel->windowLabel();

        return $expiresAt === null
            ? sprintf(
                'This conversation has no open %s window: %s has not messaged us, so Meta allows only %s.',
                $window,
                'the customer',
                $channel->outOfWindowRemedy(),
            )
            : sprintf(
                'Meta\'s %s window closed %s. Only %s may be sent now.',
                $window,
                $expiresAt->diffForHumans(),
                $channel->outOfWindowRemedy(),
            );
    }

    /**
     * How long is left, for a screen that wants to warn before it is too late.
     *
     * Null when the window is shut. An agent seeing "3 hours left" answers now;
     * one seeing nothing finds out by being refused.
     */
    public static function remaining(?Carbon $expiresAt, ?Carbon $now = null): ?string
    {
        return self::isOpen($expiresAt, $now)
            ? ($expiresAt?->diffForHumans(['parts' => 1, 'syntax' => Carbon::DIFF_ABSOLUTE]).' left')
            : null;
    }
}
