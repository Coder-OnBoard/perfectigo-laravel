<?php

namespace Perfectigo\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use Perfectigo\Laravel\Concerns\ReportsToPerfectigo;
use Perfectigo\Laravel\PerfectigoServiceProvider;
use Perfectigo\Laravel\SendPerfectigoEvent;

class Organization extends Model
{
    use ReportsToPerfectigo;

    protected $guarded = [];
}

class Member extends Model
{
    use ReportsToPerfectigo;

    protected $guarded = [];
}

/**
 * The trait is the whole integration surface: a host model reports about
 * itself, and never writes a mapping layer to do it.
 */
class ReportsToPerfectigoTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PerfectigoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('perfectigo.secret_key', 'sk_test_fake');
    }

    private function org(array $attributes = []): Organization
    {
        $org = new Organization(array_merge([
            'name' => 'Acme Dental',
            'billing_email' => 'owner@acme.test',
        ], $attributes));

        $org->id = 7;

        return $org;
    }

    public function test_it_reports_identity_from_the_model(): void
    {
        Queue::fake();

        $this->org()->reportToPerfectigo('signup', ['plan' => 'Pro']);

        Queue::assertPushed(SendPerfectigoEvent::class, fn (SendPerfectigoEvent $job) => $job->type === 'signup'
            && $job->payload['email'] === 'owner@acme.test'
            && $job->payload['company'] === 'Acme Dental'
            && $job->payload['plan'] === 'Pro');
    }

    public function test_the_key_is_scoped_to_the_record_without_being_asked(): void
    {
        Queue::fake();

        $this->org()->reportToPerfectigo('signup');

        // The trap this closes: two customers both produce "signup", and on a
        // bare key Perfectigo dedups the second away so that customer never
        // appears. Callers should not have to remember.
        Queue::assertPushed(
            SendPerfectigoEvent::class,
            fn (SendPerfectigoEvent $job) => $job->idempotencyKey === 'Organization:7:signup',
        );
    }

    public function test_two_models_sharing_an_id_do_not_share_a_key(): void
    {
        Queue::fake();

        $member = new Member(['name' => 'Acme', 'email' => 'a@b.test']);
        $member->id = 7;

        $this->org()->reportToPerfectigo('signup');
        $member->reportToPerfectigo('signup');

        $keys = [];
        Queue::assertPushed(SendPerfectigoEvent::class, function (SendPerfectigoEvent $job) use (&$keys) {
            $keys[] = $job->idempotencyKey;

            return true;
        });

        $this->assertSame(['Organization:7:signup', 'Member:7:signup'], $keys);
    }

    public function test_a_repeatable_event_takes_a_suffix(): void
    {
        Queue::fake();

        $this->org()->reportToPerfectigo('order', [], 'order:in_99');

        // An invoice can be paid many times per customer, so the occurrence has
        // to be in the key or Perfectigo records only the first one.
        Queue::assertPushed(
            SendPerfectigoEvent::class,
            fn (SendPerfectigoEvent $job) => $job->idempotencyKey === 'Organization:7:order:in_99',
        );
    }

    public function test_it_falls_back_from_billing_email_to_email(): void
    {
        Queue::fake();

        $member = new Member(['name' => 'Acme', 'email' => 'fallback@acme.test']);
        $member->id = 1;
        $member->reportToPerfectigo('signup');

        Queue::assertPushed(
            SendPerfectigoEvent::class,
            fn (SendPerfectigoEvent $job) => $job->payload['email'] === 'fallback@acme.test',
        );
    }

    public function test_a_model_with_no_usable_email_reports_nothing(): void
    {
        Queue::fake();

        $this->org(['billing_email' => '   '])->reportToPerfectigo('signup');

        // Blank, not just null: an event without an email is refused by
        // Perfectigo, so a job here could only ever 422.
        Queue::assertNothingPushed();
    }
}
