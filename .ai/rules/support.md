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

## The conversation is its own table, and `is_internal` is the whole point
`ticket_comments` is not a note on the timeline. A note is internal by nature; a
ticket comment may be **sent to the customer**, and the two must not be one row
type somebody can confuse. `is_internal` defaults to false because the dangerous
mistake is a private note the customer was not meant to see — but the ticket page
labels every internal row in as many words, because the other dangerous mistake
is believing a reply went out when it did not.

A comment can come from either side, so `author_id` is nullable and
`author_name` exists: a customer is a contact, not a user, and a reply arriving
by email later has no account behind it at all. `from_customer` is a separate
question from "author_id is null" — a departed colleague's reply also has no
author, and that one was ours. `AddTicketCommentAction` refuses to mark a
customer's own words internal, because hiding their message from them is
meaningless.

## Watchers are users, never addresses
`ticket_watchers` fills `RecipientType::Watcher`, which the matrix has always had
a row for and nothing to put in. Users only: a customer already hears as the
customer, and letting an arbitrary address watch a ticket would be a way to have
every reply forwarded somewhere nobody audits. The unique index is load-bearing —
without it a double-click doubles every later notification.

## Seven events, and the two quiet ones are quiet on purpose
`TicketNotifications` is the only place a ticket event is fired from, so the
seven cannot drift on who they reach.

- **Resolved and closed are their own events**, not also the generic status
  change. One move must never send a customer two messages, and plenty of desks
  tell a customer about the resolution and nothing else.
- **Priority changed does not list `RecipientType::Customer`** — left off the
  event, so the matrix has no cell an administrator can switch on by accident.
  How urgently we are treating something is our triage; "we have downgraded you
  to low" is not a message anybody means to send.
- **Assignment is internal too.** Which of us is holding it is not the
  customer's business, and somebody who hears every reassignment reads it as
  being passed around.
- An **internal comment** is sent to `TicketRecipients::internal()`, which is
  built without the customer rather than filtered afterwards. There is no
  ordering of conditions in which a private note goes out.
- Only **retriage** is announced from an edit. A desk that sent a message for
  every corrected subject would train everybody to ignore all of them.

`AssignTicketAction` calls `setRelation('owner', $agent)` before notifying:
`ticket.agent` names the new agent, and a stale relation would put the previous
one in the message telling somebody they now have it.

## The SLA clock is two stored due times, not a calculation
`tickets.first_response_due_at` and `resolution_due_at` are written when the
clock is set and never recomputed from the policy afterwards. Two reasons, both
load-bearing:

- **The sweep.** "Which tickets are about to breach" runs every minute against
  every open ticket. Deriving a deadline from a policy, a priority and a pause
  history in SQL would make that a join nobody can index; as stored columns it
  is a range scan.
- **The promise.** Editing a policy must not silently move a deadline a ticket
  was already given. `SlaClock::targetMinutes()` reads the policy only to place
  the *warning* inside a window whose end is already fixed.

The clock starts from `created_at`, not from when the policy was applied —
otherwise a desk buys itself time by applying it late, or by retriaging.
`ApplySlaPolicyAction` runs again on a priority change for exactly that reason,
and re-cuts from the original arrival.

## On hold pauses; pending does not
`SyncSlaClockAction` pauses only on `TicketStatus::OnHold`. **Pending is the
customer's delay and does not pause** — a desk that paused on pending could stop
every clock in the building by asking a question. This is the distinction the two
statuses exist for.

Resuming **shifts both due times forward** by the length of the hold rather than
subtracting pauses at read time. A due time that moves while a ticket is held is
the honest one to show: we promised four hours of *our* time. The alternative
displays a deadline that has already passed on a ticket that has not breached.
`sla_paused_seconds` still accumulates, so a report can say how long a ticket
spent held.

## Three things are not a first response
`RecordFirstResponseAction` ignores an internal note (the customer never saw it),
the customer's own reply (that is them chasing us) and any reply after the first.
Getting any of them wrong flatters the one number a customer signed a contract
about.

## The sweep stamps, and the stamp is what stops the repeat
`SweepSlaAction` writes `response_warned_at`, `resolution_warned_at` and the two
breach columns once each. Running every minute, nothing else would stop the same
warning going out fourteen hundred times. A breach **subsumes** the warning it
never got: a ticket that sailed past both while the scheduler was down does not
need telling it was nearly late.

Escalation raises the priority one step and stamps `escalated_at`, once per
ticket. It deliberately does **not** reassign — moving a ticket off the agent who
is already late loses the only person with any context — and does **not** re-run
the clock, which would turn a missed deadline into a reset button.

## Neither SLA event reaches the customer
`ticket.sla_warning` and `ticket.sla_breached` list only agent, admin and
watcher. Telling a customer "we are about to be late" announces a failure in
advance and gives them nothing to act on; telling them we missed it is a
conversation, not a notification.

## An empty target is no promise, not nought minutes
A `sla_targets` row with both columns null is deleted rather than stored, and the
policy form says as much beside the boxes. A blank that quietly meant
"immediately" would breach every ticket the moment it was saved.

## Analytics: two measurement decisions, and both are load-bearing
`SupportMetrics` is the only place support figures are computed.

**Resolution time subtracts holds. First-response time does not.** A ticket held
waiting for a supplier was not ours to resolve during the hold, which is exactly
what the SLA clock says — an analytics screen that disagreed with the clock
would have somebody arguing about which number was real. But a hold *before we
have answered at all* is itself a failure to answer, and `sla_paused_seconds` is
a lifetime total that would over-credit a desk for holds taken after the reply.

**Every average is reported with a median and a count.** One ticket that sat
over a bank holiday drags a mean by days. The screen leads with the median. The
same instinct puts "still waiting for a first reply" *beside* the average rather
than in it: ten answered in a minute and forty ignored is a wonderful average.

Nothing returns nought where it means "no data" — `average`, `median` and
`within_sla` are null, and `AgentPerformance::breachRate()` is null for an agent
who resolved nothing. Nought per cent of nothing is not a good record.

SLA attainment counts only tickets that were **given** a promise
(`resolution_due_at is not null`); a desk running without a policy has not met
100% of nothing.

## `sla_paused_seconds` must be CAST to SIGNED before it is subtracted
The column is UNSIGNED, so MySQL promotes the whole expression to BIGINT
UNSIGNED and a ticket whose recorded hold exceeds its wall-clock life **fails the
entire query** with error 1690 rather than clamping. `GREATEST(..., 0)` does not
save it — the subtraction happens first. `SupportMetrics::PAUSE_SECONDS` carries
the cast; use it rather than writing the column into new SQL.

## The viewer is required, never nullable
Every metric takes a `User` rather than a `?User`. A scheduled report in a later
phase runs with no session, and a null that quietly meant "everything" is exactly
how such a report leaks records the recipient could not open one by one.

## Aggregates come back through the base query builder
A `selectRaw('... as avg_seconds')` on an Eloquent builder returns Ticket models
carrying a property the model does not declare. `applyScopes()->getQuery()->get()`
gives plain rows instead — and `applyScopes()` first, or the soft-delete scope is
dropped and removed tickets come back into the figures.
