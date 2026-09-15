<?php

namespace App\Domain\Social\Enums;

/**
 * What is happening with a conversation, from this side.
 *
 * Three states, because an inbox only ever answers three questions: what needs
 * answering, what has been answered and is waiting on them, and what is
 * finished. A longer list reads well in a specification and turns into a screen
 * where nobody agrees which state a thread is in.
 *
 * The distinction that earns its place is Open against Pending, because it is
 * the one an agent sorts by: Open is work, Pending is not. It moves on its own
 * — a reply makes a thread Pending and their next message makes it Open again —
 * so it stays true without anybody maintaining it.
 *
 * Closing is not the end of anything. A customer who writes again reopens the
 * conversation automatically — the alternative is a message nobody sees because
 * it landed in a thread somebody had ticked off.
 */
enum ConversationStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Needs a reply',
            self::Pending => 'Waiting on them',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'blue',
            self::Pending => 'amber',
            self::Closed => 'slate',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
