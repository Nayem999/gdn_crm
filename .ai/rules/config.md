---
paths:
  - config/fortify.php
---

# Config

## Keep fortify.limiters.login null so lockouts stay auditable
`limiters.login` is deliberately null. Fortify's login pipeline reads `config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class` — naming a limiter *skips* its own throttle pipe and relies on the route's `throttle` middleware, which aborts with a bare 429 and never fires `Illuminate\Auth\Events\Lockout`. That makes lockouts invisible to the login_histories audit trail and shows users an error page instead of an inline message.

With it null, Fortify's EnsureLoginIsNotThrottled still caps attempts at five per minute per email + IP (LoginRateLimiter), reports the failure on the login form, and fires `Lockout` so RecordLoginHistory can log it. Don't "fix" this by re-adding a named login limiter.

The `two-factor` limiter is still named — the 2FA challenge route has no equivalent pipe, so that one is the only throttle there.
