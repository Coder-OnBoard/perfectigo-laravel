<?php

namespace Perfectigo\Laravel\Concerns;

use Perfectigo\Laravel\Perfectigo;

/**
 * Put this on the model that represents one of your customers, and report from
 * it directly. No wrapper class, no mapping layer:
 *
 *     class Organization extends Model
 *     {
 *         use ReportsToPerfectigo;
 *     }
 *
 *     $org->reportToPerfectigo('trial_started', ['plan' => 'Pro']);
 *
 * The same shape as Cashier's Billable: the package reaches into your model
 * rather than making you write an adapter for it.
 *
 * Two things the trait handles that are easy to get wrong by hand:
 *
 *   - THE IDEMPOTENCY KEY is scoped to this record automatically. Two customers
 *     reaching the same milestone both produce the same event name, and on an
 *     unscoped key Perfectigo dedups the second away — that customer simply
 *     never appears. You only pass a key when the event can happen MORE THAN
 *     ONCE per customer: an invoice id, a renewal date.
 *
 *   - IDENTITY comes from the model. Override the two methods below if your
 *     column names differ; the defaults cover the usual ones.
 */
trait ReportsToPerfectigo
{
    /**
     * Report that something happened to this customer.
     *
     * `$suffix` distinguishes repeatable events. Omit it for once-per-customer
     * moments (a signup, an activation) and pass something derived from the
     * occurrence for the rest — `"order:{$invoiceId}"`, never `now()`.
     *
     * @param  array<string, mixed>  $data
     */
    public function reportToPerfectigo(string $event, array $data = [], ?string $suffix = null): void
    {
        Perfectigo::event(
            $event,
            $this->perfectigoEmail(),
            ['company' => $this->perfectigoCompany()] + $data,
            key: $this->perfectigoKey($suffix ?? $event),
        );
    }

    /**
     * The address Perfectigo identifies this customer by.
     *
     * Override when neither column fits. An event without an email is refused,
     * so returning null here is how you say "do not report this one".
     */
    public function perfectigoEmail(): ?string
    {
        foreach (['billing_email', 'email'] as $column) {
            $value = trim((string) ($this->{$column} ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** The organization name shown in the CRM. Override if it is not `name`. */
    public function perfectigoCompany(): ?string
    {
        $value = trim((string) ($this->name ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * Scoped to this record's class and key, so two models in the same
     * application cannot collide either — an Organization 7 and a Team 7
     * reporting the same event are two different customers.
     */
    protected function perfectigoKey(string $suffix): string
    {
        return class_basename($this).':'.$this->getKey().':'.$suffix;
    }
}
