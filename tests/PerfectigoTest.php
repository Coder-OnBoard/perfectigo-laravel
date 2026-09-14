<?php

namespace Perfectigo\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use Perfectigo\Client;
use Perfectigo\Laravel\Perfectigo;
use Perfectigo\Laravel\PerfectigoServiceProvider;
use Perfectigo\Laravel\SendPerfectigoEvent;
use RuntimeException;

/**
 * What this package promises, in the order it matters:
 *
 *   1. It cannot break the caller. No key, no email, CRM down — the caller
 *      carries on regardless.
 *   2. A refused event stops; an unreachable one retries. Getting that backwards
 *      either floods the queue with unfixable work or drops real signups.
 *   3. The idempotency key reaches the wire, because that is the only thing
 *      standing between a replayed webhook and a duplicate deal.
 */
class PerfectigoTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PerfectigoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('perfectigo.secret_key', 'sk_test_fake');
        $app['config']->set('perfectigo.base_url', 'https://api.perfectigo.test');
    }

    /** A Guzzle whose responses are scripted, plus the requests it was given. */
    private function fakeHttp(array $responses, array &$recorded): Guzzle
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($recorded));

        return new Guzzle(['handler' => $stack]);
    }

    private function client(array $responses, array &$recorded): Client
    {
        return new Client(
            'https://api.perfectigo.test',
            'sk_test_fake',
            15,
            $this->fakeHttp($responses, $recorded),
        );
    }

    // -----------------------------------------------------------------
    // It cannot break the caller
    // -----------------------------------------------------------------

    public function test_no_secret_key_queues_nothing(): void
    {
        Queue::fake();
        config()->set('perfectigo.secret_key', null);

        Perfectigo::event('signup', 'a@b.test');

        // The state of CI and every developer machine. It has to be silent.
        Queue::assertNothingPushed();
    }

    public function test_no_email_queues_nothing(): void
    {
        Queue::fake();

        Perfectigo::event('signup', null);
        Perfectigo::event('signup', '   ');

        // Perfectigo identifies a person by email and refuses an event without
        // one, so a job here could only ever 422.
        Queue::assertNothingPushed();
    }

    public function test_event_returns_void_and_never_throws(): void
    {
        Queue::fake();

        $this->assertNull(Perfectigo::event('signup', 'a@b.test'));
    }

    // -----------------------------------------------------------------
    // What reaches the wire
    // -----------------------------------------------------------------

    public function test_the_idempotency_key_is_sent_as_a_header(): void
    {
        $recorded = [];
        $client = $this->client([new Response(201, [], '{"data":{}}')], $recorded);

        $client->sendEvent('order', ['email' => 'a@b.test'], 'org:42:order:in_1');

        $request = $recorded[0]['request'];
        $this->assertSame('org:42:order:in_1', $request->getHeaderLine('Idempotency-Key'));
    }

    public function test_no_key_sends_no_header_rather_than_an_empty_one(): void
    {
        $recorded = [];
        $client = $this->client([new Response(201, [], '{"data":{}}')], $recorded);

        $client->sendEvent('order', ['email' => 'a@b.test'], null);

        // An empty header is not the same as no header: Perfectigo would key on
        // the empty string and collapse every such event into one.
        $this->assertFalse($recorded[0]['request']->hasHeader('Idempotency-Key'));
    }

    public function test_the_type_and_payload_are_posted_to_the_events_endpoint(): void
    {
        $recorded = [];
        $client = $this->client([new Response(201, [], '{"data":{}}')], $recorded);

        $client->sendEvent('trial_started', ['email' => 'a@b.test', 'plan' => 'Pro']);

        /** @var Request $request */
        $request = $recorded[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/public/v1/events', $request->getUri()->getPath());
        $this->assertSame('Bearer sk_test_fake', $request->getHeaderLine('Authorization'));
        $this->assertSame(
            ['type' => 'trial_started', 'email' => 'a@b.test', 'plan' => 'Pro'],
            $body,
        );
    }

    public function test_empty_values_are_dropped_from_the_payload(): void
    {
        Queue::fake();

        Perfectigo::event('signup', 'a@b.test', ['plan' => null, 'company' => '', 'source' => 'app']);

        Queue::assertPushed(SendPerfectigoEvent::class, function (SendPerfectigoEvent $job) {
            // Sending `plan: null` would overwrite a plan Perfectigo already
            // knows with nothing.
            return $job->payload === ['email' => 'a@b.test', 'source' => 'app'];
        });
    }

    public function test_a_false_consent_survives_the_payload_filter(): void
    {
        Queue::fake();

        Perfectigo::event('signup', 'a@b.test', ['consent' => false, 'consent_text' => 'I agree…']);

        Queue::assertPushed(SendPerfectigoEvent::class, function (SendPerfectigoEvent $job) {
            // The trap in array_filter: `false` is empty. Dropping it turns
            // "they said no" into "they did not answer", which is a different
            // and legally worse claim.
            return array_key_exists('consent', $job->payload) && $job->payload['consent'] === false;
        });
    }

    // -----------------------------------------------------------------
    // Retry or stop
    // -----------------------------------------------------------------

    public function test_a_refused_event_stops_instead_of_retrying(): void
    {
        $recorded = [];
        $client = $this->client([new Response(422, [], '{"error":{"code":"validation_failed"}}')], $recorded);

        $job = new SendPerfectigoEvent('never_declared', ['email' => 'a@b.test']);

        // No exception: retrying an undeclared event type three more times only
        // delays whatever is behind it in the queue.
        $job->handle($client);

        $this->assertCount(1, $recorded);
    }

    public function test_a_server_error_is_retried(): void
    {
        $recorded = [];
        $client = $this->client([new Response(502, [], 'Bad Gateway')], $recorded);

        $job = new SendPerfectigoEvent('signup', ['email' => 'a@b.test']);

        $this->expectException(RuntimeException::class);

        $job->handle($client);
    }

    public function test_an_unreachable_host_is_retried_not_dropped(): void
    {
        $recorded = [];
        $client = $this->client(
            [new \GuzzleHttp\Exception\ConnectException('DNS failure', new Request('POST', '/'))],
            $recorded,
        );

        $job = new SendPerfectigoEvent('signup', ['email' => 'a@b.test']);

        // Losing a signup from the CRM because of a deploy is not acceptable.
        $this->expectException(RuntimeException::class);

        $job->handle($client);
    }

    public function test_a_job_queued_before_the_key_was_removed_does_not_send_after_it(): void
    {
        $recorded = [];
        $client = $this->client([new Response(201, [], '{}')], $recorded);

        config()->set('perfectigo.secret_key', null);

        (new SendPerfectigoEvent('signup', ['email' => 'a@b.test']))->handle($client);

        $this->assertCount(0, $recorded);
    }

    // -----------------------------------------------------------------
    // Queue placement
    // -----------------------------------------------------------------

    public function test_it_uses_the_configured_queue(): void
    {
        Queue::fake();
        config()->set('perfectigo.queue', 'telemetry');

        Perfectigo::event('signup', 'a@b.test');

        Queue::assertPushed(
            SendPerfectigoEvent::class,
            fn (SendPerfectigoEvent $job) => $job->queue === 'telemetry',
        );
    }

    public function test_an_unset_queue_leaves_the_application_default(): void
    {
        Queue::fake();
        config()->set('perfectigo.queue', null);

        Perfectigo::event('signup', 'a@b.test');

        Queue::assertPushed(
            SendPerfectigoEvent::class,
            fn (SendPerfectigoEvent $job) => $job->queue === null,
        );
    }
}
