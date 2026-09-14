<?php

namespace Perfectigo;

/**
 * What came back.
 *
 * The distinction that matters is not success/failure but **retry or stop**,
 * and the two failures look nothing alike:
 *
 *   4xx — Perfectigo refused the event, almost always because its type has not
 *         been declared in Settings → Integrations. Retrying a configuration
 *         problem three more times only delays the queue behind it.
 *   5xx / transport — Perfectigo is briefly unwell. Worth another attempt;
 *         losing a signup from the CRM because of a deploy is not acceptable.
 */
class Result
{
    /** Status 0 means the request never reached a server. */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
    ) {
    }

    public static function transportFailure(string $message): self
    {
        return new self(0, $message);
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Perfectigo understood the request and said no. Do not retry. */
    public function refused(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /** A server error or no server at all. Retry. */
    public function shouldRetry(): bool
    {
        return ! $this->successful() && ! $this->refused();
    }

    /**
     * Something short enough for a log line.
     *
     * Truncated because the body can be a full HTML error page from whatever
     * proxy sits in front of the API, and a log entry per failed event is not
     * the place for it.
     */
    public function summary(): string
    {
        $body = trim($this->body);

        if ($body === '') {
            return "HTTP {$this->status}";
        }

        return "HTTP {$this->status}: ".(mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'…' : $body);
    }
}
