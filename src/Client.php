<?php

namespace Perfectigo;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Throwable;

/**
 * One POST to Perfectigo's event endpoint.
 *
 * Deliberately free of any framework: no Laravel imports, no facades, no
 * container. The Laravel package wraps this; when a second framework needs a
 * client, this class moves to `perfectigo/php` under the same FQCN and nothing
 * that depends on it has to change.
 *
 * It also knows NOTHING about what events mean. `signup`, `trial_started`,
 * `licence_renewed` are your application's words for moments in your funnel,
 * declared in Perfectigo's own settings. A client that validated them would
 * need a release every time one of its consumers invented a lifecycle stage.
 */
class Client
{
    public function __construct(
        protected string $baseUrl,
        protected string $secretKey,
        protected int $timeout = 15,
        protected ?Guzzle $http = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Report that something happened to one of your customers.
     *
     * `$idempotencyKey` is what makes a replayed webhook, a retried job or a
     * cron that ran twice land ONCE. Derive it from the thing that happened —
     * an invoice id, a date — never from the time of sending. Pass null and
     * Perfectigo falls back to hashing the payload, which is right for events
     * that carry an `external_id` and wrong for anything else.
     *
     * @param  array<string, mixed>  $payload  At minimum an `email`.
     */
    public function sendEvent(string $type, array $payload, ?string $idempotencyKey = null): Result
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->secretKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            $response = $this->guzzle()->post($this->baseUrl.'/api/public/v1/events', [
                'headers' => $headers,
                'json' => ['type' => $type] + $payload,
                'timeout' => $this->timeout,
                'connect_timeout' => 5,
                // Errors are read, not thrown: the caller decides what a 422
                // means, and for this integration it means "stop", not "retry".
                'http_errors' => false,
            ]);

            return new Result($response->getStatusCode(), (string) $response->getBody());
        } catch (ConnectException $e) {
            // DNS, TLS, refused, timed out — worth another attempt.
            return Result::transportFailure($e->getMessage());
        } catch (RequestException $e) {
            return Result::transportFailure($e->getMessage());
        } catch (Throwable $e) {
            return Result::transportFailure($e->getMessage());
        }
    }

    protected function guzzle(): Guzzle
    {
        return $this->http ??= new Guzzle;
    }
}
