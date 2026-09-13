---
paths:
  - 'app/Domain/Support/**'
  - 'app/Livewire/Support/**'
  - 'resources/views/livewire/support/**'
---

# Support (tickets)

## `status` has exactly one writer, and it owns the two stamps
`status` is **not** in `Ticket::$fillable`, and `TicketData` has no status field. `ChangeTicketStatusAction` is the only thing that writes it, the same arrangement that keeps `MoveDealStageAction` the only writer of a deal's stage. A form control for it would be a second path to "resolved", and the two would eventually disagree about `resolved_at`.

That action also owns `resolved_at` and `closed_at`, because the stamps are not independent facts — they are consequences of the move:

- Reaching **Resolved or Closed** stamps `resolved_at`, keeping the first one if it is already set. A ticket closed without ever being marked resolved *was* resolved at that moment; ignoring those would flatter every resolution-time figure 9.5 reports.
- Only **Closed** stamps `closed_at`. Going Closed → Resolved clears it, which is the honest answer: somebody has reopened the conversation even if they think the fix stands.
- Moving back to an open status clears **both**. A ticket on somebody's queue that still says it was resolved on Tuesday reads as done, and would be counted as done.

`TicketFactory::withStatus()` sets the status and its stamps together for the same reason — a fixture saying "resolved" with no `resolved_at` describes something the application cannot produce, and a test written against it proves nothing.

## Resolved and Closed are not the same status, and neither are Pending and On hold
Six statuses, deliberately. Resolved is "we think it is fixed"; Closed is "nobody is coming back to it". Support teams are measured on the gap between them and a single "done" throws that away.

Pending (waiting on the customer) and OnHold (waiting on us — a supplier, a part) are separate for 9.3: the SLA clock pauses on hold and keeps running on pending, because waiting for a reply is the customer's delay and waiting for a supplier is ours.

`TicketStatus::openValues()` is the single source for "still on somebody's queue" — `scopeOpen()`, the queue's quick filters and the totals all read it, so adding a status does not leave one of them silently short.

## The reference is derived from the id, in `created` not `creating`
`TKT-00042` comes from the primary key, padded. Written in a `created` hook because the id does not exist until the row does, and saved with `saveQuietly()` so it does not write a second audit entry.

That costs one extra UPDATE per ticket. It buys a number that is unique without a sequence table and without the race a `max(id) + 1` would carry — two agents raising a ticket in the same second is an ordinary event on a support desk, not an edge case. `reference()` falls back to `TKT-?????` for an unsaved instance so a view never prints "null".

## Priority is an integer rank
Same reason as `ActivityPriority`: the queue is sorted by priority, and a string column sorts "high, low, normal, urgent" alphabetically — visibly wrong on the one screen an agent lives in. `TicketPriority::options()` keys by the rank **as a string**, because that is what a dropdown and a filter condition carry.

## Age is not a sort key
`ageInHours()` runs from `created_at` to the resolution when there is one and to now when there is not. Sorting that in SQL would need a CASE the query does not carry, and would disagree with the figure on screen for every open ticket. `TicketFields::sortColumn('age')` returns null on purpose; the export writes it as a **number**, not "3.5 hours", so a column of them can be averaged.

## A removed ticket still has a page
`tickets.show` binds `->withTrashed()` and `TicketShow` loads `withTrashed()`. A ticket is a conversation with a customer, and a customer reading a reference down the telephone must not get a 404 because somebody removed it. The page opens, says the ticket was removed, and hides every control. `TicketPolicy::isVisibleTo()` is `withTrashed()` for the same reason.

Deletion is soft throughout: removing the row would take the ticket's notes, documents and history with it, and "we have no record of that" is the worst answer a support desk can give.

## A contact from another account is refused, not stored
Both `CreateTicketAction` and `UpdateTicketAction` reject a contact whose `account_id` disagrees with the chosen account. Storing it would put the ticket on the wrong customer's history, which nobody finds again. `TicketFactory::forContact()` sets both columns together so a fixture cannot build the state the actions refuse.

The contact picker is **not** narrowed when no account is chosen. Plenty of tickets come from somebody whose organisation nobody has recorded yet, and refusing to offer them would mean typing the account first to raise a ticket at all.

## "Unlinked" means no customer, not no agent
`owner_id` is NOT NULL — every ticket has an agent, so "unassigned" is not a state this module can be in. What actually goes missing is a ticket nobody attached to a contact or an account, which cannot be found by searching for the person who raised it. That is what the `unlinked` chip filters, and the wording on screen says so.
