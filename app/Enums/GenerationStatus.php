<?php

namespace App\Enums;

/**
 * Local lifecycle of a generation. "submitting" is ours: the row exists but
 * Pixelforge hasn't confirmed it yet. The rest mirror Pixelforge's statuses.
 *
 * Statuses only ever move forward: submitting → queued → processing →
 * succeeded | failed. Final states never change, so a late or duplicated
 * webhook can't drag a generation backwards.
 */
enum GenerationStatus: string
{
    case Submitting = 'submitting';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function isPending(): bool
    {
        return ! $this->isFinal();
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this->isFinal()) {
            return false;
        }

        return $next->rank() > $this->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Submitting => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    /**
     * @return list<string>
     */
    public static function pendingValues(): array
    {
        return [self::Submitting->value, self::Queued->value, self::Processing->value];
    }
}
