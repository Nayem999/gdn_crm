<?php

namespace App\Domain\Social\Enums;

/**
 * What Meta currently thinks of a template.
 *
 * **Meta's vocabulary, stored as Meta spells it.** These come back from Graph in
 * upper case and are sent nowhere, so translating them into a house style would
 * buy nothing and cost a mapping to keep in step the day Meta adds a state.
 *
 * Only `Approved` may be sent, and that is checked at send time against the
 * stored value rather than assumed from the fact that a row exists: Meta pauses
 * a template when customers block or report the messages, and it does so without
 * telling anybody here. A template that was approved last week is not
 * necessarily approved now, which is exactly why `synced_at` is beside it.
 */
enum TemplateStatus: string
{
    case Approved = 'APPROVED';
    case Pending = 'PENDING';
    case Rejected = 'REJECTED';
    /**
     * Meta stopped it because of how customers reacted. Recoverable — it comes
     * back on its own if the quality recovers — which is why it is not the same
     * as rejected.
     */
    case Paused = 'PAUSED';
    case Disabled = 'DISABLED';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Pending => 'Waiting on Meta',
            self::Rejected => 'Rejected',
            self::Paused => 'Paused by Meta',
            self::Disabled => 'Disabled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Approved => 'emerald',
            self::Pending => 'amber',
            self::Rejected, self::Disabled => 'rose',
            self::Paused => 'orange',
        };
    }

    /**
     * Whether a message may be sent with this template.
     */
    public function isSendable(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Why it cannot be sent, in words somebody can act on.
     */
    public function refusal(): ?string
    {
        return match ($this) {
            self::Approved => null,
            self::Pending => 'Meta has not finished reviewing this template.',
            self::Rejected => 'Meta rejected this template. Edit it in Business Manager and submit it again.',
            self::Paused => 'Meta has paused this template because of how customers responded to it.',
            self::Disabled => 'This template has been disabled and cannot be used.',
        };
    }

    public static function fromMeta(?string $status): self
    {
        return self::tryFrom(strtoupper(trim((string) $status))) ?? self::Pending;
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
