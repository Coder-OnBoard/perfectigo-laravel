<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Perfectigo
    |--------------------------------------------------------------------------
    |
    | Report your application's lifecycle events to Perfectigo, which turns them
    | into CRM stages, deals and automations.
    |
    */

    'base_url' => rtrim((string) env('PERFECTIGO_BASE_URL', 'https://api.perfectigo.com'), '/'),

    /*
    | Server-to-server key (sk_…), from Perfectigo → Settings → Integrations →
    | API keys. Shown once.
    |
    | LEAVE THIS EMPTY OUTSIDE PRODUCTION. Empty disables the integration
    | entirely and every report becomes a silent no-op — the correct state for
    | CI and every developer machine, and the reason nothing warns when it is
    | unset. A warning on every signup teaches people to ignore warnings.
    */
    'secret_key' => env('PERFECTIGO_SECRET_KEY'),

    /*
    | Which queue the reporting job goes on.
    |
    | Give it its own, or at least not the one carrying anything a user waits
    | for. Marketing telemetry must never be why somebody's password-reset email
    | is late. Null uses the application's default queue.
    */
    'queue' => env('PERFECTIGO_QUEUE'),

    /*
    | Seconds to wait for Perfectigo before treating the call as failed and
    | letting the job retry.
    */
    'timeout' => (int) env('PERFECTIGO_TIMEOUT', 15),

    /*
    | The exact wording printed beside your marketing opt-in checkbox.
    |
    | Send it with the event that records consent:
    |
    |     $user->reportToPerfectigo('signup', [
    |         'consent'      => $ticked,
    |         'consent_text' => config('perfectigo.consent_text'),
    |     ]);
    |
    | It lives in config rather than at the call site because a consent record
    | is evidence of what the person READ. A paraphrase typed from memory makes
    | it evidence of something nobody agreed to — so there is one copy, and it
    | changes in lockstep with the form.
    */
    'consent_text' => env('PERFECTIGO_CONSENT_TEXT'),
];
