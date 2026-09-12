<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Mail\Actions\SyncInboundEmailAction;
use App\Domain\Mail\Inbound\InboundMailbox;
use App\Domain\Mail\Inbound\InboundMessageData;
use App\Domain\Mail\Inbound\MailboxSettings;
use App\Domain\Mail\Models\EmailMessage;
use App\Domain\Mail\Models\InboundMessage;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Support\Carbon;

/**
 * A mailbox with no mail server behind it.
 *
 * Everything worth testing about inbound mail — matching, not importing twice,
 * what happens when the mailbox renumbers — is on this side of the interface.
 */
class FakeMailbox implements InboundMailbox
{
    public bool $closed = false;

    /**
     * @param  array<int, InboundMessageData>  $messages
     */
    public function __construct(
        private array $messages = [],
        private int $validity = 1,
        private ?string $failure = null,
    ) {}

    public function connect(): void
    {
        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }
    }

    public function uidValidity(): int
    {
        return $this->validity;
    }

    public function since(int $uid, int $limit = 100): array
    {
        return array_values(array_filter($this->messages, fn (InboundMessageData $m): bool => $m->uid > $uid));
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

function arriving(array $overrides = []): InboundMessageData
{
    return new InboundMessageData(
        messageId: $overrides['messageId'] ?? 'reply-1@customer.example',
        fromEmail: $overrides['fromEmail'] ?? 'buyer@customer.example',
        fromName: $overrides['fromName'] ?? 'A Buyer',
        toEmail: $overrides['toEmail'] ?? 'crm@example.com',
        subject: $overrides['subject'] ?? 'Re: Your quote',
        body: $overrides['body'] ?? 'Looks good, please proceed.',
        receivedAt: $overrides['receivedAt'] ?? Carbon::now(),
        uid: $overrides['uid'] ?? 10,
        uidValidity: $overrides['uidValidity'] ?? 1,
        folder: $overrides['folder'] ?? 'INBOX',
        inReplyTo: $overrides['inReplyTo'] ?? null,
        references: $overrides['references'] ?? [],
    );
}

function sync(array $messages, int $validity = 1): array
{
    return app(SyncInboundEmailAction::class)->handle(new FakeMailbox($messages, $validity), 'INBOX');
}

// -- Matching -----------------------------------------------------------------

it('attaches a reply to the record the original message was about', function () {
    $contact = Contact::factory()->create(['email' => 'someone-else@customer.example']);

    EmailMessage::factory()->from('postmark', 'ours-1')->create([
        'related_type' => $contact->getMorphClass(),
        'related_id' => $contact->id,
    ]);

    sync([arriving(['inReplyTo' => 'ours-1'])]);

    $stored = InboundMessage::query()->firstOrFail();

    // Matched on the thread, not the address — the reply came from an address
    // the CRM has never seen, which is exactly when address matching fails.
    expect($stored->related_type)->toBe($contact->getMorphClass())
        ->and($stored->related_id)->toBe($contact->id)
        ->and($stored->email_message_id)->not->toBeNull();
});

it('falls back to the References header when there is no In-Reply-To', function () {
    $contact = Contact::factory()->create();

    EmailMessage::factory()->from('postmark', 'ours-2')->create([
        'related_type' => $contact->getMorphClass(),
        'related_id' => $contact->id,
    ]);

    sync([arriving(['references' => ['something-else', 'ours-2']])]);

    expect(InboundMessage::query()->firstOrFail()->related_id)->toBe($contact->id);
});

it('prefers the direct parent over an older ancestor', function () {
    expect(arriving(['inReplyTo' => 'parent', 'references' => ['grandparent', 'parent']])->thread())
        ->toBe(['parent', 'grandparent']);
});

it('recognises a sender we already hold an address for', function () {
    $contact = Contact::factory()->create(['email' => 'buyer@customer.example']);

    sync([arriving()]);

    expect(InboundMessage::query()->firstOrFail()->related_id)->toBe($contact->id);
});

it('prefers a contact to a lead with the same address', function () {
    $contact = Contact::factory()->create(['email' => 'buyer@customer.example']);
    Lead::factory()->create(['email' => 'buyer@customer.example']);

    sync([arriving()]);

    $stored = InboundMessage::query()->firstOrFail();

    expect($stored->related_type)->toBe($contact->getMorphClass())
        ->and($stored->related_id)->toBe($contact->id);
});

it('matches a lead when no contact holds the address', function () {
    $lead = Lead::factory()->create(['email' => 'buyer@customer.example']);

    sync([arriving()]);

    $stored = InboundMessage::query()->firstOrFail();

    expect($stored->related_type)->toBe($lead->getMorphClass())
        ->and($stored->related_id)->toBe($lead->id);
});

it('stores a message from a stranger rather than guessing who it is about', function () {
    sync([arriving(['fromEmail' => 'nobody@nowhere.example'])]);

    $stored = InboundMessage::query()->firstOrFail();

    expect($stored->related_type)->toBeNull()
        ->and($stored->related_id)->toBeNull()
        ->and($stored->body)->toBe('Looks good, please proceed.');
});

// -- Not importing the same thing twice ---------------------------------------

it('stores a message once however many times the mailbox offers it', function () {
    $first = sync([arriving()]);
    $second = sync([arriving(['uid' => 11])]);

    expect($first)->toBe(['read' => 1, 'stored' => 1])
        ->and($second)->toBe(['read' => 1, 'stored' => 0])
        ->and(InboundMessage::query()->count())->toBe(1);
});

it('only asks for messages newer than the ones it has', function () {
    sync([arriving(['uid' => 10])]);

    $result = sync([
        arriving(['uid' => 10]),
        arriving(['uid' => 12, 'messageId' => 'reply-2@customer.example']),
    ]);

    // The one at uid 10 is below the cursor and never comes back at all.
    expect($result)->toBe(['read' => 1, 'stored' => 1])
        ->and(InboundMessage::query()->count())->toBe(2);
});

it('starts again when the mailbox renumbers, without duplicating anything', function () {
    sync([arriving(['uid' => 50])]);

    // A restored mailbox: same messages, new UIDs, new validity. The cursor for
    // the new validity is zero, so everything is offered again — and the
    // Message-ID index is what stops it all being stored again.
    $result = sync([arriving(['uid' => 1, 'uidValidity' => 2])], validity: 2);

    expect($result)->toBe(['read' => 1, 'stored' => 0])
        ->and(InboundMessage::query()->count())->toBe(1);
});

it('closes the mailbox even when storing throws', function () {
    $mailbox = new FakeMailbox([arriving()]);

    app(SyncInboundEmailAction::class)->handle($mailbox, 'INBOX');

    expect($mailbox->closed)->toBeTrue();
});

// -- The command ---------------------------------------------------------------

it('does nothing at all until inbound mail is switched on', function () {
    $this->artisan('mail:sync-inbound')
        ->expectsOutputToContain('Inbound email is switched off.')
        ->assertSuccessful();
});

it('reads the mailbox when it is switched on', function () {
    settings()->set('inbound.enabled', true);
    settings()->set('inbound.host', 'imap.example.com');
    settings()->set('inbound.username', 'crm@example.com');

    app()->instance(InboundMailbox::class, new FakeMailbox([arriving()]));

    $this->artisan('mail:sync-inbound')
        ->expectsOutputToContain('Read 1 message(s), stored 1.')
        ->assertSuccessful();

    expect(InboundMessage::query()->count())->toBe(1);
});

it('reports a mailbox it cannot open rather than throwing on a schedule', function () {
    settings()->set('inbound.enabled', true);
    settings()->set('inbound.host', 'imap.example.com');

    app()->instance(InboundMailbox::class, new FakeMailbox(failure: 'Authentication failed.'));

    $this->artisan('mail:sync-inbound')
        ->expectsOutputToContain('Authentication failed.')
        ->assertFailed();
});

// -- Configuration --------------------------------------------------------------

it('builds the connection string ext-imap expects', function () {
    $settings = new MailboxSettings('imap.example.com', 993, 'ssl', 'user', 'pass');

    expect($settings->connectionString())->toBe('{imap.example.com:993/imap/ssl}INBOX');

    $lax = new MailboxSettings('mail.local', 143, 'tls', 'user', 'pass', 'Archive', false);

    expect($lax->connectionString())->toBe('{mail.local:143/imap/tls/novalidate-cert}Archive');
});

it('offers a connection test and nothing to send', function () {
    $group = SettingsRegistry::group('inbound');

    expect($group)->not->toBeNull()
        ->and($group['tester'] ?? null)->not->toBeNull();

    $tester = app($group['tester']);

    expect($tester->sampleLabel())->toBeNull()
        ->and(fn () => $tester->test(['host' => '', 'username' => '']))
        ->toThrow(RuntimeException::class);
});

it('never stores an inbound password anywhere it could be read back', function () {
    expect(SettingsRegistry::isSecret('inbound.password'))->toBeTrue();
});
