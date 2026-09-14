<?php

namespace App\Domain\Meta\Graph;

use RuntimeException;

/**
 * A refusal from Meta, in a form the rest of the application can act on.
 *
 * Meta answers every failure with the same envelope and a numeric code, and the
 * code is the only part worth branching on — the message is English prose that
 * changes without notice. Three of them mean something specific here:
 *
 *   - the token is dead, so reconnecting is the fix and retrying is not;
 *   - a permission is missing, so App Review or a re-authorisation is the fix;
 *   - we are throttled, so waiting is the fix.
 *
 * Everything else is "Meta said no", which the caller logs and shows as the
 * safe message rather than the raw one. §34 of the brief: never show a raw
 * technical exception to a CRM user.
 */
class MetaApiException extends RuntimeException
{
    /** The access token has expired, been revoked, or was never valid. */
    private const TOKEN_CODES = [190, 102, 463, 467];

    /** The app or the user lacks a permission the call needs. */
    private const PERMISSION_CODES = [10, 200, 272, 294, 299];

    /** Throttled — application, page, or business use case. */
    private const RATE_LIMIT_CODES = [4, 17, 32, 613, 80004, 80007, 80008, 80014];

    /**
     * @param  array<string, mixed>  $error  Meta's own error object.
     */
    public function __construct(
        string $message,
        public readonly ?int $code_ = null,
        public readonly ?int $subcode = null,
        public readonly ?string $type = null,
        public readonly ?string $traceId = null,
        public readonly ?int $status = null,
        public readonly array $error = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Build from a Graph response body.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(array $body, ?int $status = null): self
    {
        /** @var array<string, mixed> $error */
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? (string) $error['message']
            : 'Meta refused the request and gave no reason.';

        return new self(
            message: $message,
            code_: isset($error['code']) ? (int) $error['code'] : null,
            subcode: isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            type: isset($error['type']) ? (string) $error['type'] : null,
            traceId: isset($error['fbtrace_id']) ? (string) $error['fbtrace_id'] : null,
            status: $status,
            error: $error,
        );
    }

    public static function transport(string $reason): self
    {
        return new self('Meta could not be reached: '.$reason);
    }

    public function isTokenProblem(): bool
    {
        return in_array($this->code_, self::TOKEN_CODES, true);
    }

    public function isPermissionProblem(): bool
    {
        return in_array($this->code_, self::PERMISSION_CODES, true);
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429 || in_array($this->code_, self::RATE_LIMIT_CODES, true);
    }

    /**
     * Whether calling again could plausibly work. A permission or a dead token
     * will fail identically every time, and retrying them wastes the rate limit
     * that the calls which *could* succeed need.
     */
    public function isRetryable(): bool
    {
        if ($this->isTokenProblem() || $this->isPermissionProblem()) {
            return false;
        }

        return $this->isRateLimited()
            || $this->status === null
            || $this->status >= 500;
    }

    /**
     * What a CRM user is allowed to see: what went wrong and what to do, in our
     * words rather than Meta's, and never a credential or a trace id.
     */
    public function userMessage(): string
    {
        return match (true) {
            $this->isTokenProblem() => 'The connection to Meta is no longer authorised. Reconnect it under Settings → Meta.',
            $this->isPermissionProblem() => 'Meta refused this because the connection is missing a permission it needs. Reconnect and grant it, or check the app\'s review status.',
            $this->isRateLimited() => 'Meta is rate limiting this connection. It will be retried automatically in a few minutes.',
            default => 'Meta could not complete this request. The details are in the integration log.',
        };
    }

    /**
     * The detail worth keeping, with nothing in it that could identify a
     * credential. Safe for the log and the delivery record.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'meta_code' => $this->code_,
            'meta_subcode' => $this->subcode,
            'meta_type' => $this->type,
            'fbtrace_id' => $this->traceId,
            'http_status' => $this->status,
            'message' => $this->getMessage(),
        ], fn (mixed $value): bool => $value !== null);
    }
}
