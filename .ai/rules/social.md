---
paths:
  - 'app/Domain/Social/**'
  - 'app/Livewire/Social/**'
  - 'resources/views/livewire/social/**'
---

# Social

## An unknown social sender becomes a lead on the first message
A Messenger or WhatsApp conversation from somebody not already linked to a lead or contact creates a lead immediately, on the first inbound message — it does not wait for an email or phone number the way the website chat widget does.

Why: unlike an anonymous website visitor, a social sender is durably reachable — the thread itself is the address, and an agent can answer it tomorrow. Confirmed as the intended behaviour when 12.8 was built, with the volume trade-off understood: every "hi" becomes a lead.

How to apply: keep the immediate creation in RecordInboundMessageAction for every channel, including 12.10's WhatsApp. The lead carries the channel's own LeadSource and no email or phone, which is legitimate here even though the lead *form* requires one of the two.

## WhatsApp timestamps are seconds; Messenger's are milliseconds
Meta sends `messaging[].timestamp` in **milliseconds** on Messenger and
`messages[].timestamp` in **seconds** on WhatsApp Cloud. Divide the wrong one and
the message lands in 1970 or in the year 56000 — and because the reply window is
measured from that instant, a thread that is open looks closed, or worse, a
closed thread looks open and the send fails at Meta.

## Delivery status only ever moves forward
`sent → delivered → read`, never back. Meta's `statuses[]` arrive out of order
routinely: a `delivered` after a `read` is normal traffic, not a correction, and
overwriting would make the inbox lie about what the customer has seen.
`RecordDeliveryReceiptAction::isProgressFrom()` owns that ordering. The one
exception is failure, which always wins whatever came before — a message that
failed is the fact an agent needs.

## A template's approval is checked at send time, not at sync time
Meta pauses a template when customers report or block it and tells nobody, so a
row that said APPROVED an hour ago may not now. `SendWhatsAppTemplateAction`
refuses in words before any Graph call — wrong status, wrong number of variables,
or a template belonging to a different WABA — because a refusal an agent can read
beats a numeric error code from Meta.

Placeholders come from the body text, never from Meta's `example` block: the
example is optional and the `{{1}}` in the body is not.

## The referral arrives once, and only on the first message
Meta names the advertisement a conversation came from in a `referral` object on
the customer's **first** message. It is not repeated on their second, it is not
on the conversation, and it cannot be fetched afterwards — so a thread that did
not store it has permanently lost which campaign won the customer.

`social_conversations.referral` keeps it, first touch wins, and both the
automatic lead and the inbox's "create lead" button read it from there, so they
attribute identically however long apart they happen.

Meta spells the same fact differently per channel: WhatsApp `source_id` +
`ctwa_clid`, Messenger `ad_id` + `ref`. `ClickToMessageReferral` reads both;
never read the array directly.

A customer already in the CRM gets `enrichAttribution` (gaps filled, first-touch
`captured_at` kept), never `recordAttribution` — an advertisement they clicked
today does not rewrite where they originally came from.

## Messenger has history; WhatsApp has none
`GET /{page-id}/conversations` returns a Page's past conversations with their
messages, so an inbox connected today can be filled with what came before.
`GET /{waba-id}/conversations` and `/messages` answer **"(#100) Tried accessing
nonexisting field"** — the WhatsApp Cloud API has no history endpoint at all. A
WhatsApp thread therefore begins at the first delivered webhook and there is
nothing to backfill; say so rather than offering a button that returns nothing.

`ImportMessengerHistoryAction` threads on the **customer's page-scoped id**, not
Meta's `t_` conversation id, so an imported thread and a later live delivery are
one conversation. It is idempotent on the message id, and it creates **no leads**
unless an owner is passed: a stranger writing today is an enquiry worth a lead,
two years of history is not.
