<?php

namespace Perfectigo\Laravel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Perfectigo\Client;
use RuntimeException;
use Throwable;

/**
 * Queued because the caller's path is not ours to spend.
 *
 * These calls hang off registration, off payment webhooks, off middleware that
 * runs on every page render — places where an extra second of somebody else's
 * latency is unacceptable and a failure is unthinkable. A customer must be able
 * to sign up while the CRM is down.
 *
 * The payload travels as plain data rather than as models: by the time a retry
 * runs, a subscription may have changed status, and an event has to describe
 * the moment it fired rather than quietly rewrite history.
 */
class SendPerfectigoEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 20;

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly ?string $idempotencyKey = null,
    ) {
        $queue = config('perfectigo.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    /**
     * Roughly 20 minutes across three delays — enough to ride out a deploy or a
     * brief outage. Deliberately not a day: this is marketing data, and an
     * event that reaches the CRM tomorrow morning is worth less than a queue
     * that stays clear.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 300, 900];
    }

    public function handle(Client $client): void
    {
        // Re-checked here, not only at dispatch: a job queued before the key
        // was removed must not try to send after it.
        if (! Perfectigo::configured()) {
            return;
        }

        $result = $client->sendEvent($this->type, $this->payload, $this->idempotencyKey);

        if ($result->successful()) {
            return;
        }

        if ($result->refused()) {
            // Almost always: the type has not been declared in Perfectigo under
            // Settings → Integrations → "Events your app sends". Three more
            // attempts cannot fix a configuration problem.
            Log::warning('[perfectigo] event refused, not retrying', [
                'type' => $this->type,
                'result' => $result->summary(),
            ]);

            return;
        }

        throw new RuntimeException("Perfectigo could not be reached: {$result->summary()}");
    }

    /**
     * Every attempt failed. Logged, not escalated: the consequence is a CRM row
     * that is briefly out of date, which is not worth waking anybody for.
     */
    public function failed(?Throwable $e): void
    {
        Log::warning('[perfectigo] gave up reporting an event', [
            'type' => $this->type,
            'idempotency_key' => $this->idempotencyKey,
            'error' => $e?->getMessage(),
        ]);
    }
}
