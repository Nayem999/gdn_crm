<?php

namespace App\Domain\Settings\Contracts;

/**
 * The "does this actually work?" half of a settings group.
 *
 * Some groups hold credentials for something outside the application, and for
 * those, saving is not the same as working. A tester lets the screen answer the
 * only question the administrator has — does this reach the service, and does a
 * message get through — before they find out from a customer who never received
 * anything.
 *
 * It is handed the **merged** values: what is stored, overridden by whatever has
 * been typed but not yet saved. That is deliberate. Testing only what is stored
 * would mean saving credentials you are not sure about before you can find out
 * whether they are right.
 */
interface SettingsGroupTester
{
    /**
     * Reach the service with these credentials.
     *
     * Returns the line to show on success. On failure it throws, and the
     * message is shown to the administrator — so it must carry the service's
     * own words and must never carry a credential.
     *
     * @param  array<string, mixed>  $values
     */
    public function test(array $values): string;

    /**
     * The label of the "send one to me" button, or null when the group has
     * nothing to send.
     */
    public function sampleLabel(): ?string;

    public function destinationLabel(): string;

    /**
     * @return array<int, string>
     */
    public function destinationRules(): array;

    /**
     * @param  array<string, mixed>  $values
     */
    public function sendSample(array $values, string $destination): string;
}
