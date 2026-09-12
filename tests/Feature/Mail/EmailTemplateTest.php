<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Mail\Actions\RenderEmailTemplateAction;
use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Models\EmailMessage;
use App\Domain\Mail\Models\EmailTemplate;
use App\Domain\Mail\Templates\MergeFields;
use App\Domain\Mail\Tracking\EmailTracking;
use App\Livewire\Mail\EmailTemplates;
use App\Mail\TemplatedEmail;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function templateUser(array $permissions = ['email-templates.view', 'email-templates.update']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

function render(EmailTemplate $template, $subject = null, ?User $sender = null)
{
    return app(RenderEmailTemplateAction::class)->handle($template, $subject, $sender);
}

// -- Merge fields --------------------------------------------------------------

it('fills a template in from the record it is about', function () {
    $contact = Contact::factory()->create([
        'first_name' => 'Priya',
        'last_name' => 'Ramanathan',
        'email' => 'priya@example.com',
    ]);

    $sender = User::factory()->create(['name' => 'Sam Rep']);

    $template = EmailTemplate::factory()->create([
        'subject' => 'Hello {{contact.first_name}}',
        'body' => '<p>Dear {{contact.full_name}}, I am {{sender.name}} at {{company.name}}.</p>',
    ]);

    $rendered = render($template, $contact, $sender);

    expect($rendered->subject)->toBe('Hello Priya')
        ->and($rendered->html)->toContain('Dear Priya Ramanathan')
        ->and($rendered->html)->toContain('I am Sam Rep');
});

it('renders an unknown field as nothing rather than leaving braces in a customer message', function () {
    $template = EmailTemplate::factory()->create([
        'subject' => 'Hi {{contact.first_name}}',
        'body' => 'Your {{contact.favourite_colour}} order.',
    ]);

    expect(render($template, Contact::factory()->create(['first_name' => 'Ana']))->html)
        ->toBe('Your  order.');
});

it('names the fields a template uses that its module does not offer', function () {
    $template = EmailTemplate::factory()->create([
        'module' => 'lead',
        'subject' => 'Hi {{lead.first_name}}',
        'body' => 'About {{quote.total}} and {{lead.company_name}}.',
    ]);

    expect($template->unknownFields())->toBe(['quote.total']);
});

it('never compiles a template, whatever an administrator writes in it', function () {
    $template = EmailTemplate::factory()->create([
        'subject' => 'Hi',
        'body' => '{{ $undefined }} and <?php echo "no"; ?>',
    ]);

    $html = render($template, Contact::factory()->create())->html;

    // The Blade-looking token is not a valid field name, so it is left exactly
    // as written rather than evaluated.
    expect($html)->toContain('<?php echo "no"; ?>')
        ->and($html)->toContain('{{ $undefined }}');
});

it('offers the merge fields of the module it is written for, and no others', function () {
    $lead = MergeFields::for('lead');

    expect(array_keys($lead))->toContain('lead.first_name')
        ->and(array_keys($lead))->not->toContain('quote.total')
        // The sender and the company are on every message.
        ->and(array_keys($lead))->toContain('sender.name', 'company.name');
});

// -- Tracking -------------------------------------------------------------------

it('leaves a template alone when tracking is off', function () {
    $rendered = render(EmailTemplate::factory()->create(['body' => '<p><a href="https://example.com">Link</a></p>']));

    expect($rendered->trackingId)->toBeNull()
        ->and($rendered->html)->toContain('href="https://example.com"')
        ->and($rendered->html)->not->toContain('/e/o/');
});

it('adds a pixel and rewrites links when tracking is on', function () {
    $template = EmailTemplate::factory()->tracked()->create([
        'body' => '<html><body><p><a href="https://example.com/quote">See it</a></p></body></html>',
    ]);

    $rendered = render($template);

    expect($rendered->trackingId)->not->toBeNull()
        ->and($rendered->html)->toContain('/e/o/'.$rendered->trackingId)
        ->and($rendered->html)->toContain('/e/c/'.$rendered->trackingId)
        ->and($rendered->html)->not->toContain('href="https://example.com/quote"')
        // Inside the document, and at the end of it.
        ->and($rendered->html)->toContain('width="1" height="1"')
        ->and(strpos($rendered->html, 'width="1"'))->toBeGreaterThan(strpos($rendered->html, 'See it'));
});

it('leaves alone the links that tracking would break', function () {
    $template = EmailTemplate::factory()->tracked()->create([
        'body' => '<a href="mailto:sales@example.com">Mail us</a> <a href="tel:+880123">Call</a>',
    ]);

    $html = render($template)->html;

    expect($html)->toContain('href="mailto:sales@example.com"')
        ->and($html)->toContain('href="tel:+880123"');
});

it('records an open when the pixel is fetched, and returns an image either way', function () {
    $message = EmailMessage::factory()->create(['tracking_id' => 'abc-123']);

    $response = $this->get(EmailTracking::openUrl('abc-123'));

    $response->assertOk()->assertHeader('Content-Type', 'image/gif');

    expect($response->getContent())->toBe(EmailTracking::PIXEL)
        ->and($message->refresh()->status)->toBe(EmailStatus::Opened)
        ->and($message->open_count)->toBe(1);

    // An id nobody recognises still gets a pixel: the person fetching it is a
    // customer, and a broken image is a worse outcome than a lost statistic.
    $this->get(EmailTracking::openUrl('nobody'))->assertOk();
});

it('records a click and sends the reader where they were going', function () {
    $message = EmailMessage::factory()->create(['tracking_id' => 'click-1']);

    $this->get(EmailTracking::clickUrl('click-1', 'https://example.com/quote'))
        ->assertRedirect('https://example.com/quote');

    $message->refresh();

    expect($message->status)->toBe(EmailStatus::Clicked)
        ->and($message->click_count)->toBe(1)
        ->and($message->events->first()->url)->toBe('https://example.com/quote');
});

it('refuses a tracking link somebody has edited', function () {
    EmailMessage::factory()->create(['tracking_id' => 'click-2']);

    $tampered = str_replace(
        urlencode('https://example.com/quote'),
        urlencode('https://evil.example/phish'),
        EmailTracking::clickUrl('click-2', 'https://example.com/quote')
    );

    // Signed over the whole URL, so swapping the destination invalidates it.
    // Without this the application is an open redirect wearing its own domain.
    $this->get($tampered)->assertForbidden();
});

it('refuses a destination that is not a web address', function () {
    $this->get(EmailTracking::clickUrl('click-3', 'javascript:alert(1)'))->assertNotFound();
});

it('carries the tracking id out on the message and stores it against the log row', function () {
    Http::fake(['*' => Http::response(['MessageID' => 'pm-tracked'])]);

    settings()->set('mail.provider', 'postmark');
    settings()->set('mail.postmark_token', 'token-1');

    $rendered = render(EmailTemplate::factory()->tracked()->create(['body' => '<p>Hi</p>']));

    Mail::mailer('crm')->to('buyer@example.com')->send(new TemplatedEmail($rendered));

    expect(EmailMessage::query()->firstOrFail()->tracking_id)->toBe($rendered->trackingId);
});

// -- The screen -----------------------------------------------------------------

it('needs a permission to read templates and another to change them', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.email-templates'))
        ->assertForbidden();

    $this->actingAs(templateUser(['email-templates.view']))
        ->get(route('settings.email-templates'))
        ->assertOk();

    Livewire::actingAs(templateUser(['email-templates.view']))
        ->test(EmailTemplates::class)
        ->call('create')
        ->assertForbidden();
});

it('creates a template and warns about a field that will never resolve', function () {
    Livewire::actingAs(templateUser())
        ->test(EmailTemplates::class)
        ->call('create')
        ->set('name', 'Follow up')
        ->set('module', 'lead')
        ->set('subject', 'Hello {{lead.first_name}}')
        ->set('body', 'About {{quote.total}}')
        ->assertSee('will come out blank')
        ->call('save')
        ->assertHasNoErrors();

    expect(EmailTemplate::query()->where('name', 'Follow up')->exists())->toBeTrue();
});

it('refuses a module the merge fields do not know', function () {
    Livewire::actingAs(templateUser())
        ->test(EmailTemplates::class)
        ->call('create')
        ->set('name', 'Bad')
        ->set('module', 'invented')
        ->set('subject', 'Hi')
        ->set('body', 'Body')
        ->call('save')
        ->assertHasErrors(['module']);
});
