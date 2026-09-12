<?php

namespace App\Domain\Messaging\Contracts;

use App\Domain\Settings\SettingField;

/**
 * One way of getting a short message to a phone.
 *
 * Deliberately the same shape as MailProvider — its settings, whether they are
 * filled in, a way to check them, and a send — because the two channels have
 * the same lifecycle and the settings screen already knows how to drive that
 * shape. SMS and WhatsApp share the interface rather than having one each: a
 * Twilio account sends both, and the difference between them is a prefix on a
 * number, not a different idea.
 */
interface MessagingProvider
{
    /**
     * The stored value of `{group}.provider` when this one is active.
     */
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /**
     * The settings this provider adds to its group, keys already prefixed.
     *
     * @return array<int, SettingField>
     */
    public function fields(): array;

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<int, string> Labels of what is missing; empty means ready.
     */
    public function missingRequirements(array $credentials): array;

    /**
     * @param  array<string, mixed>  $credentials
     *
     * @throws \RuntimeException
     */
    public function verify(array $credentials): void;

    /**
     * @param  array<string, mixed>  $credentials
     * @return string The provider's id for the message.
     *
     * @throws \RuntimeException
     */
    public function send(array $credentials, string $to, string $body): string;
}
