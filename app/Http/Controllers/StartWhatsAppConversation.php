<?php

namespace App\Http\Controllers;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Social\Actions\StartWhatsAppConversationAction;
use App\Domain\Social\Models\SocialConversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * "Message on WhatsApp", from a lead or a contact.
 *
 * §19 asks for sending from a record, and this is the door: it opens or finds
 * the conversation and sends the person to the inbox, where the window rules,
 * the templates and the thread already live. The alternative — a compose box on
 * every record screen — would be a second place for Meta's rules to be
 * implemented and a second place for them to be wrong.
 *
 * **Two explicit routes rather than one taking a module name.** A request that
 * named its own model class is one typo away from arbitrary instantiation, which
 * is the rule `IngestionTargets` and `ApiModules` already follow.
 *
 * POST, not GET: it creates a conversation, and a GET that changes state is one
 * a browser can be made to make.
 */
class StartWhatsAppConversation extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly StartWhatsAppConversationAction $start) {}

    public function lead(Lead $lead): RedirectResponse
    {
        $this->authorize('view', $lead);

        return $this->open($lead, route('leads.show', $lead));
    }

    public function contact(Contact $contact): RedirectResponse
    {
        $this->authorize('view', $contact);

        return $this->open($contact, route('contacts.show', $contact));
    }

    /**
     * @param  Lead|Contact  $record
     */
    private function open(Model $record, string $back): RedirectResponse
    {
        // Seeing a record is not the same as being allowed to message somebody
        // from the company's number.
        $this->authorize('start', SocialConversation::class);

        try {
            $conversation = ($this->start)($record);
        } catch (RuntimeException $exception) {
            // The refusals are all actionable — no number on the record, no
            // connected number to send from — so they go back to the record the
            // person was looking at.
            return redirect()->to($back)->with('error', $exception->getMessage());
        }

        return redirect()->route('social.inbox', ['conversation' => $conversation->getKey()]);
    }
}
