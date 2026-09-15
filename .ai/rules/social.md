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
