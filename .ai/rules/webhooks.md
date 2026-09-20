---
paths:
  - 'app/Domain/Workflows/Webhooks/**'
---

# Webhooks

## Two webhook guards: refuse() for typed addresses, refuseSelfCall() for our own
`WebhookTarget::refuse()` refuses anything resolving inside the network. That is right for an address somebody configured and wrong for calling our own published address: a server routinely resolves its own domain to loopback or a LAN address (split-horizon DNS, /etc/hosts — the ordinary shape of shared hosting), so the strict guard reported "gdncrm.goldeninfotech.net resolves to an address inside this network" about the site's own public domain and the Meta webhook self-test never ran.

`refuseSelfCall()` is the same check with that one allowance: scheme and resolution still enforced, link-local (169.254/16, the cloud metadata service) still refused, private and loopback permitted. Use it only for an address this application generated for itself and only behind a permission — `MetaConnection::testWebhook()` is the sole caller. Anything a person typed goes through `refuse()`.

A pass from a self-call that resolved internally proves the route, token and certificate, not that Meta can reach the address; `testWebhook()` checks `allows()` separately and says so in the result.
