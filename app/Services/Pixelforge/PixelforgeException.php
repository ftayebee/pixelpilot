<?php

namespace App\Services\Pixelforge;

use RuntimeException;
use Throwable;

/**
 * A request to Pixelforge that didn't succeed. Carries the documented error
 * fields so callers can branch on `errorCode` rather than on messages.
 */
class PixelforgeException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorType = null,
        public readonly ?string $param = null,
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
        public readonly bool $connectionFailed = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function connection(string $message, ?Throwable $previous = null): self
    {
        return new self($message, connectionFailed: true, previous: $previous);
    }

    /**
     * Documented as safe to retry: overloads, per-minute and concurrency
     * limits, plain 5xx, and network failures. The daily quota is a 429 too,
     * but retrying won't help until the next UTC day.
     */
    public function isRetryable(): bool
    {
        if ($this->connectionFailed) {
            return true;
        }

        return match (true) {
            $this->errorCode === 'daily_limit_exceeded' => false,
            $this->status === 429 => true,
            $this->status === 503 => true,
            $this->status !== null && $this->status >= 500 => true,
            default => false,
        };
    }

    /**
     * The request may or may not have reached Pixelforge (timeout, dropped
     * connection). For creation, the generation may exist upstream.
     */
    public function isAmbiguous(): bool
    {
        return $this->connectionFailed || ($this->status !== null && $this->status >= 500 && $this->status !== 503);
    }

    public function isContentPolicyViolation(): bool
    {
        return $this->errorCode === 'content_policy_violation';
    }

    public function isValidationError(): bool
    {
        return $this->errorCode === 'validation_failed';
    }

    /**
     * A sentence that is safe to show to an end user.
     */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy. Nothing was created and you were not charged.',
            'validation_failed' => $this->getMessage(),
            'rate_limit_exceeded', 'concurrency_limit_exceeded' => 'Pixelforge is busy with other images right now. We will keep trying.',
            'daily_limit_exceeded' => 'The daily image limit has been reached. Please try again after midnight UTC. You were not charged.',
            'overloaded' => 'Pixelforge is temporarily overloaded. We will keep trying.',
            'invalid_api_key' => 'Image generation is temporarily unavailable. Our team has been notified.',
            default => $this->connectionFailed
                ? 'We could not reach Pixelforge. We will keep trying.'
                : 'Pixelforge returned an unexpected error.',
        };
    }
}
