<?php

namespace App\Services\Generations;

use App\Services\Pixelforge\PixelforgeException;

final readonly class SubmissionResult
{
    private function __construct(
        public string $outcome,
        public ?string $message = null,
        public ?int $retryInSeconds = null,
        public ?PixelforgeException $error = null,
    ) {}

    public static function accepted(): self
    {
        return new self('accepted');
    }

    public static function retrying(string $message, int $delay): self
    {
        return new self('retrying', $message, $delay);
    }

    public static function rejected(string $message, PixelforgeException $error): self
    {
        return new self('rejected', $message, error: $error);
    }

    public function isAccepted(): bool
    {
        return $this->outcome === 'accepted';
    }

    public function isRetrying(): bool
    {
        return $this->outcome === 'retrying';
    }

    public function isRejected(): bool
    {
        return $this->outcome === 'rejected';
    }
}
