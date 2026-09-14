<?php

namespace Perfectigo\Laravel;

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
