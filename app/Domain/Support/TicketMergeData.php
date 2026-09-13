<?php

namespace App\Domain\Support;

use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\Models\TicketComment;

/**
 * The merge values every ticket notification carries.
 *
 * One place, because seven events describe the same thing and a template naming
 * a field its event does not declare is refused at save time. Keep this in step
 * with the merge fields the ticket events declare in NotificationEventRegistry
 * — a test walks both and compares them. ActivityMergeData does the same job
 * for activities.
 */
final class TicketMergeData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Ticket $ticket): array
    {
        return [
            'ticket' => [
                'reference' => $ticket->reference(),
                'subject' => $ticket->subject,
                'status' => $ticket->status()->label(),
                'priority' => $ticket->priority()->label(),
                // owner_id is NOT NULL and restrictOnDelete, so there is always
                // somebody on the other end of this.
                'agent' => $ticket->owner->name,
                'customer' => self::customerName($ticket),
            ],
        ];
    }

    /**
     * Who raised it: the person if we know one, otherwise their organisation.
     *
     * Written out rather than chained with `??`, because `?->name` on the left
     * of a `??` is not the question being asked — `name` is never null on an
     * account that exists.
     */
    private static function customerName(Ticket $ticket): string
    {
        $contact = $ticket->contact;

        if ($contact !== null) {
            return $contact->fullName();
        }

        $account = $ticket->account;

        return $account !== null ? $account->name : 'nobody in particular';
    }

    /**
     * A move, with both ends of it.
     *
     * The old status is what makes a status-change message worth reading: "your
     * ticket is now open" says much less than "moved from waiting on you to
     * open".
     *
     * @return array<string, mixed>
     */
    public static function forMove(Ticket $ticket, TicketStatus $from, TicketStatus $to): array
    {
        $data = self::for($ticket);
        $data['ticket']['old_status'] = $from->label();
        $data['ticket']['new_status'] = $to->label();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forPriority(Ticket $ticket, TicketPriority $from, TicketPriority $to): array
    {
        $data = self::for($ticket);
        $data['ticket']['old_priority'] = $from->label();
        $data['ticket']['new_priority'] = $to->label();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forComment(Ticket $ticket, TicketComment $comment): array
    {
        $data = self::for($ticket);
        $data['comment'] = [
            'author' => $comment->authorLabel(),
            // The excerpt, not the whole body: an SMS has 160 characters and a
            // subject line has fewer, and a reply can run to pages.
            'excerpt' => $comment->excerpt(),
        ];

        return $data;
    }

    /**
     * A promise, and where it stands.
     *
     * @return array<string, mixed>
     */
    public static function forSla(Ticket $ticket, string $kind): array
    {
        $clock = app(SlaClock::class);
        $data = self::for($ticket);

        $data['sla'] = [
            'promise' => $kind === SlaClock::RESPONSE ? 'first response' : 'resolution',
            'policy' => self::policyName($ticket),
            'due' => $clock->dueAt($ticket, $kind)?->format('j M Y, H:i') ?? 'not set',
            'remaining' => $clock->label($ticket, $kind) ?? 'not set',
        ];

        return $data;
    }

    /**
     * The policy's name, or a stand-in. Written out because `?->name` on the
     * left of a `??` is not the question — a policy that exists always has one.
     */
    private static function policyName(Ticket $ticket): string
    {
        $policy = $ticket->slaPolicy;

        return $policy === null ? 'no policy' : $policy->name;
    }

    /**
     * The fields common to every ticket event.
     *
     * @return array<string, string>
     */
    public static function mergeFields(): array
    {
        return [
            'ticket.reference' => 'The ticket number',
            'ticket.subject' => 'What it is about',
            'ticket.status' => 'Where it has got to',
            'ticket.priority' => 'How urgent it is',
            'ticket.agent' => 'The agent it is with',
            'ticket.customer' => 'Who raised it',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function moveFields(): array
    {
        return [
            ...self::mergeFields(),
            'ticket.old_status' => 'Where it was',
            'ticket.new_status' => 'Where it is now',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function priorityFields(): array
    {
        return [
            ...self::mergeFields(),
            'ticket.old_priority' => 'What it was',
            'ticket.new_priority' => 'What it is now',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function slaFields(): array
    {
        return [
            ...self::mergeFields(),
            'sla.promise' => 'Which promise — first response or resolution',
            'sla.policy' => 'The policy it was given',
            'sla.due' => 'When it was due',
            'sla.remaining' => 'How long is left, or how long ago it went',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function commentFields(): array
    {
        return [
            ...self::mergeFields(),
            'comment.author' => 'Who replied',
            'comment.excerpt' => 'The opening of what they said',
        ];
    }
}
