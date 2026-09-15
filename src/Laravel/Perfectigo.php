<?php

namespace Perfectigo\Laravel;

use Illuminate\Support\Facades\Log;

/**
 * The whole API:
 *
 *     Perfectigo::event('trial_started', $user->email, [
 *         'company' => $org->name,
 *         'plan'    => 'Pro',
 *     ], key: "org:{$org->id}:trial_started");
 *
 * Returns void and throws nothing. This is marketing telemetry: it hangs off
 * registrations, payment webhooks and page renders, and none of those may fail
 * because a CRM is down.
 */
class Perfectigo
{
    /**
     * Report that something happened to one of your customers.
     *
     * The event `$type` is YOUR word for a moment in YOUR funnel, declared in
     * Perfectigo under Settings → Integrations → "Events your app sends". This
     * package never validates it: a list here would need a release every time
     * one of its consumers invented a lifecycle stage, which is the coupling
     * the settings screen exists to remove.
     *
     * `$key` is the idempotency key. Derive it from the thing that happened —
     * an invoice id, a trial end date — never from the time of sending, and
     * scope it to the customer so two people reaching the same milestone do
     * not collide on one key and silently drop the second. Omit it only when
     * the payload carries an `external_id`, which Perfectigo keys on instead.
     *
     * @param  array<string, mixed>  $data  company, plan, amount, currency,
     *                                      external_id, source, consent,
     *                                      consent_text — all optional.
     */
    public static function event(string $type, ?string $email, array $data = [], ?string $key = null): void
    {
        $email = trim((string) $email);

        // Perfectigo identifies a person by email and refuses an event without
        // one, so a customer we cannot name is nothing useful to send — and a
        // job that can only 422 is queue noise.
        if ($email === '' || ! self::configured()) {
            return;
        }

        // A consent record is only worth anything as evidence of what the
        // person READ. Ticked box, no wording, and the receiving end falls back
        // to a generic sentence nobody was ever shown — a record that looks
        // compliant and proves nothing.
        //
        // Warned rather than refused: dropping the event would lose a real
        // opt-in, which is worse. But it is the one thing in this package that
        // is worth a log line, because there is no other way to find out.
        if (($data['consent'] ?? null) === true && trim((string) ($data['consent_text'] ?? '')) === '') {
            Log::warning('[perfectigo] consent reported with no wording', [
                'event' => $type,
                'hint' => "Pass consent_text — the exact sentence beside your checkbox. config('perfectigo.consent_text') is the usual home for it.",
            ]);
        }

        // Billing providers deal in MINOR units — Stripe, Paddle and Braintree
        // all send 9900 for $99. Converting at the call site is one `/ 100`
        // somebody eventually forgets, and the result is a pipeline reporting a
        // hundred times the real revenue: plausible right up until it is totted
        // up. Pass the raw figure as amount_minor and it cannot go wrong.
        //
        // Zero stays null rather than becoming 0: a trial converting on a
        // full-discount coupon is a real conversion, but a zero-value deal is
        // noise in every revenue chart.
        if (array_key_exists('amount_minor', $data)) {
            $minor = $data['amount_minor'];
            unset($data['amount_minor']);

            $data['amount'] = is_numeric($minor) && (int) $minor > 0 ? ((int) $minor) / 100 : null;
        }

        dispatch(new SendPerfectigoEvent(
            $type,
            array_filter(['email' => $email] + $data, static fn ($v) => $v !== null && $v !== ''),
            $key,
        ));
    }

    /**
     * Is there a key to send with?
     *
     * No key means the integration is off, and off is a SILENT no-op — the
     * state of CI and every developer machine. Nothing warns, because a warning
     * on every signup teaches people to ignore warnings.
     */
    public static function configured(): bool
    {
        return ! empty(config('perfectigo.secret_key'));
    }
}
