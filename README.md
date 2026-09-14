# perfectigo/laravel

Report your application's lifecycle events to [Perfectigo](https://perfectigo.com)
— signups, trials, activations, payments, churn — and let it turn them into CRM
stages, deals and automations.

```php
Perfectigo::event('trial_started', $user->email, [
    'company' => $org->name,
    'plan'    => 'Pro',
], key: "org:{$org->id}:trial_started");
```

That is the whole API. It returns `void`, throws nothing, and does the work on a
queue.

---

## Install

```bash
composer require perfectigo/laravel
```

The service provider is auto-discovered. Then:

```bash
php artisan vendor:publish --tag=perfectigo-config   # optional
```

```dotenv
PERFECTIGO_SECRET_KEY=sk_…      # empty = integration off
PERFECTIGO_QUEUE=telemetry      # optional; defaults to your default queue
```

**Leave `PERFECTIGO_SECRET_KEY` empty outside production.** Empty disables the
integration entirely and every report becomes a silent no-op — the correct state
for CI and every developer machine, and the reason nothing warns when it is
unset. A warning on every signup teaches people to ignore warnings.

---

## Declare your events first

Perfectigo **refuses an event type it has not been told about**, with a 422. That
is deliberate: a typo should surface, not be silently dropped.

So before you send anything, in Perfectigo → **Settings → Integrations → "Events
your app sends"**, declare each event and choose what it does to the CRM record:

| | |
|---|---|
| **Nothing** | records the event, starts any workflow watching for it |
| **Mark as won** | moves them to your Won stage, records a deal if the payload carries an amount |
| **Mark as lost** | moves them to your Lost stage |

`signup`, `order` and `plan_changed` exist for every organization already. Your
own — `trial_started`, `seat_added`, `licence_renewed` — you add there.

**This package never validates the type.** The names are yours, and a list baked
in here would need a release every time one of its consumers invented a lifecycle
stage. That coupling is exactly what the settings screen exists to remove.

---

## Idempotency

The second argument you should think hardest about.

```php
Perfectigo::event('order', $email, [...], key: "org:{$org->id}:order:{$invoice->id}");
```

Perfectigo applies one key **once**. Stripe replays webhooks, queues retry, and
daily commands get re-run — without a key derived from *the thing that happened*,
the same moment lands in the CRM twice and your customer has two deals.

Two rules:

- **Derive it from the event, never from the time of sending.** An invoice id, a
  subscription id, a trial end date. `now()` in a key is the same as no key.
- **Scope it to the customer.** Two people signing up both produce `signup`; on a
  bare `"signup"` key Perfectigo would dedup the second away and that customer
  would never appear.

Omit `key` only when the payload carries an `external_id` — Perfectigo keys on
that instead.

---

## What you can send

`email` is required (it is how Perfectigo identifies a person; an event without
one is refused). Everything else is optional:

| Field | Notes |
|---|---|
| `company` | Their organization's name |
| `plan` | Free-text plan name |
| `amount` | **Major units.** If your billing provider deals in cents, divide by 100 here — reporting `9900` where `99` was meant shows a pipeline worth a hundred times the real revenue, and looks plausible until somebody totals it |
| `currency` | ISO 4217, e.g. `USD` |
| `external_id` | Your id for the thing; also acts as an idempotency key |
| `source` | Free-text, e.g. `myapp_backend` |
| `consent` | `true`/`false` — did they tick a marketing opt-in? |
| `consent_text` | **The exact wording they saw.** A consent record is evidence of what somebody read; a paraphrase makes it evidence of something nobody agreed to |

`null` and `''` are dropped before sending, so a missing plan does not overwrite
a plan Perfectigo already knows. `false` is **not** dropped — `consent: false`
means "they said no", which is a different claim from "they did not answer".

---

## What happens when things go wrong

Nothing reaches your caller. `Perfectigo::event()` returns `void` and throws
nothing, because these calls hang off registrations, payment webhooks and page
renders — places where a failure is unthinkable. **A customer must be able to
sign up while the CRM is down.**

Inside the queued job:

| | |
|---|---|
| **4xx** | Logged and dropped. Almost always an undeclared event type; three more attempts cannot fix a configuration problem, and retrying only delays the queue behind it |
| **5xx / unreachable** | Retried — 4 attempts over roughly 20 minutes, enough to ride out a deploy |
| **All attempts failed** | Logged. The consequence is a CRM row briefly out of date, which is not worth waking anybody for |

Put it on its own queue (`PERFECTIGO_QUEUE`). Marketing telemetry must never be
why somebody's password-reset email is late.

---

## What is not in here

**Your funnel.** This package knows about events and identities; it does not know
what a trial is, or that your product has agents. Mapping *your* domain onto
`Perfectigo::event(...)` belongs in your application, where that knowledge lives —
usually one small class with a method per moment:

```php
final class Funnel
{
    public static function trialStarted(Organization $org): void
    {
        Perfectigo::event('trial_started', $org->billing_email, [
            'company' => $org->name,
            'plan'    => $org->subscription?->plan?->name,
        ], key: "org:{$org->id}:trial_started");
    }
}
```

That class is a hundred lines you write once. Everything below it — HTTP, auth,
retries, queueing, the idempotency header, the payload filter — is this package.

---

## Tests

```bash
composer install
vendor/bin/phpunit
```

They run against a scripted Guzzle handler, so no network and no Perfectigo
account is needed.
